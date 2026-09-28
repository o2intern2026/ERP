# 第三方物流 / 系统对接手册（Carrier & API Integration Guide）

本手册给要把 ERP 和外部系统连起来的开发者。两个方向：

1. **ERP → 承运商 / 物流平台**（第 1–8 节）：让运输模块通过某家承运商的 API 报价、预订、打面单、查轨迹。写一个**承运商适配器**（`CarrierAdapter`）就够了，运输模块的业务代码不动。Karrio、Transdirect、自有车队、人工录价都是这样接进来的。
2. **客户 / 上游系统 → ERP**（第 9 节）：客户的系统把订单或整张清单推给 ERP（**订单 API**）。

英文速查在文末（English quick reference）。所有约定以代码为准：接口 `app/Support/Contracts/CarrierAdapter.php`（冻结区），模板 `app/Modules/Transport/Adapters/ExampleHttpCarrierAdapter.php`，合同测试 `tests/Support/CarrierAdapterTestCase.php`（CHANGE_REQUESTS #163）。

---

## 0. 三分钟看懂

- 运输模块只认一个接口：`App\Support\Contracts\CarrierAdapter`。每家承运商一个实现类，`source()` 返回它的代号（`karrio`、`transdirect`、`own_fleet`、`manual`……），按代号在 `carrier_services` 表里找它开放的服务等级。
- **单位约定**：金额是整数**分**（AUD，含 GST），尺寸 **mm**，重量 **kg**。适配器负责把承运商的单位换算过来，其它地方不换算。
- **没有报价 = 返回空数组**，永远不把异常抛给业务层；网络错误（`ConnectionException`）自己接住。**永不报 $0**——缺价是"缺价异常"，不是免费。
- 适配器只报**成本**（承运商收我们的价）。客户价由客户价目表的加价规则算（`RateService`），适配器不管。
- 新接一家 = 复制模板 → 实现 → 注册 → 配置密钥 → 服务等级行 → 测试 → 沙箱 → 上线（第 4 节清单）。

```
订单确认 / 打包完成 ──► TransportOptionService::quote(shipment, stage)
                          │ 对每个 active 的 carrier_services.source 找到适配器
                          │ adapter->quote(request) ──► 候选方案 ──► 客户价（RateService）──► transport_quotes
                          ▼
调度 / 客户 确认最终方案 ──► ShipmentBookingService::book ──► adapter->book(request, service_code, {quote_ref, pickup_date})
                          ▼
面单  ShipmentLabelService ──► adapter->label(booking_ref)
轨迹  transport:sync-tracking（每 30 分钟）──► adapter->tracking(booking_ref)
取消  adapter->cancel(booking_ref)
```

## 1. 接口：`CarrierAdapter` 的七个方法

| 方法 | 什么时候被调 | 返回 |
|---|---|---|
| `source(): string` | 注册时、匹配 `carrier_services.source` 时 | 小写代号，如 `acme` |
| `capabilities(): array` | 每次用前 | `['quote'=>bool, 'book'=>bool, 'cancel'=>bool, 'label'=>bool, 'tracking'=>'poll'\|'webhook'\|'none', 'pod'=>'api'\|'manual']` |
| `quote(array $request): array` | 初步报价（下单）和最终报价（打包后）；每次都把全部方案要一遍 | `list<方案>`（第 3 节）；没有就 `[]` |
| `book(array $request, string $serviceCode, array $options = []): array` | 调度 / 客户确认最终方案后点"确认预订"，或批量预订 | 预订结果（第 3 节）；失败也返回结果，`status` 说明失败 |
| `cancel(string $bookingRef): bool` | 取消预订 | 承运商接受取消 → `true` |
| `label(string $bookingRef): ?string` | 打印面单 / 运单 | PDF 字节（`%PDF-` 开头），没有 → `null` |
| `tracking(string $bookingRef): array` | `tracking='poll'` 的来源每 30 分钟 | `list<轨迹事件>`；`none` / `webhook` 返回 `[]` |

`book()` 抛 `InvalidArgumentException` 只用于"这次调用永远不可能成功"（例如人工预订没填参考号）；`ShipmentBookingService` 会把异常消息当失败原因记下来。其它情况都用返回值表达。

## 2. 请求对象（`quote()` / `book()` 的 `$request`）

由 `ShipmentQuoteRequestFactory::build()` 组装，结构固定：

| 键 | 类型 | 说明 |
|---|---|---|
| `client_id` | int | 客户 |
| `sender` / `receiver` | array | 发件 / 收件方：`name`、`company_name`、`phone`、`email`、`address`、`suburb`、`state`、`postcode`、`type`（`business` \| `residential`）。地址四项缺一个就别报价，返回 `[]` |
| `items` | list | 每种包裹一行：`description`、`qty`、`weight_kg`、`length_mm`、`width_mm`、`height_mm`。承运商按件计价的，把 `qty` 展开成多个 parcel |
| `declared_value_cents` | int | 申报价值，分 |
| `description` | string | ERP 运输单号（如 `SHP-20260928-0001`），请写到承运商的 reference 字段 |
| `tailgate_pickup` / `tailgate_delivery` | bool | 提货 / 送货是否需要尾板 |
| `requested_date` | ?string | 客户要求日期 `Y-m-d`；作为提货日候选 |
| `zone` | string | 收件邮编（自有车队按区计价用） |

人工录价的请求多一个 `manual_quotes`（只有 `manual` 来源用）。

## 3. 返回对象

**报价方案（`quote()` 列表中的一项）**

| 键 | 类型 | 说明 |
|---|---|---|
| `service_code` | string | 承运商自己的服务码，**原样**回传给 `book()` |
| `service_name` | string | 显示名 |
| `service_level` | string | 只能是 `standard` / `express` / `same_day`（`TransportEnums::SERVICE_LEVELS`），见第 5 节匹配规则 |
| `cost_cents` | int ≥ 1 | 成本，分，含 GST |
| `eta_days` | ?int | 时效天数；为空时用 `carrier_services.default_eta_days`，两个都空 → 该方案被丢弃 |
| `pickup_dates` | list<string> | 可提货日期 `Y-m-d`；第一项是预订时的默认提货日 |
| `raw` | array | 承运商原始响应；**`raw.booking_id`** 会被保存为 `_booking.quote_ref`，预订时通过 `$options['quote_ref']` 交回给 `book()` |

**预订结果（`book()`）**

| 键 | 说明 |
|---|---|
| `booking_ref` | 承运商的托运单号 / 预订号，之后 `label()`、`tracking()`、`cancel()` 都用它 |
| `tracking_number` | 追踪号，可 `null` |
| `label_path` | 面单 URL / 路径，可 `null`（面单也可以之后由 `label()` 取） |
| `status` | 承运商状态原文。`booked` / `confirmed` 等视为成功；空串、`new`、`pending_payment`、`pending_review`、`request_failed`、`cancelled` 视为**未预订**（`ShipmentBookingService::NOT_BOOKED_STATUSES`）。失败时 ERP 会生成 manual_transport 异常，并把 `raw` 里第一段可读文字（`message` / `detail` / `error`）摘给操作员看 |
| `raw` | 原始响应 |

`book()` 的 `$options`：`quote_ref`（见上）、`pickup_date`（人填的提货日，没填则用 `pickup_dates[0]`）。

**轨迹事件（`tracking()` 列表中的一项）**：`status`（承运商状态原文）、`description`、`location`、`occurred_at`（ISO 8601）、`raw`。ERP 按**关键词**把 `status` 映射到运单状态：含 `deliver` / `complete` → 已送达；`in transit` / `out for delivery` / `on board` → 运输中；`dispatch` / `picked up` / `collected` → 已发运；`fail` / `undeliverable` / `exception` → 配送失败；其它只记录不改状态（`TrackingSyncService::targetStatus`）。承运商状态是代码而不是英文句子时，请在适配器里翻成上面这些词。

## 4. 接入步骤清单（以承运商 "Acme" 为例）

| 步 | 做什么 | 文件 / 位置 | 谁改 |
|---|---|---|---|
| 1 | 复制模板 `ExampleHttpCarrierAdapter.php` → `AcmeAdapter.php`，`source()` 返回 `acme`，改 `client()`、`payload()`、五个方法的请求 / 响应映射 | `app/Modules/Transport/Adapters/` | X2 |
| 2 | 配置：`config/services.php` 加 `'acme' => ['api_key' => env('ACME_API_KEY'), 'base_url' => env('ACME_BASE_URL', '…')]`；`.env.example` 加注释行。**真实密钥只放服务器 `.env` 和密码库** | 冻结区 → 在 `contracts/CHANGE_REQUESTS.md` 提变更 | C |
| 3 | 注册：`TransportServiceProvider::register()` 里 `singleton(AcmeAdapter::class)` 并加入 `transport.carrier-adapters` tag | `app/Modules/Transport/TransportServiceProvider.php` | X2 |
| 4 | 枚举：`TransportEnums::SOURCES` 加 `acme`，`contracts/enums.md` 同步；`lang/zh/transport.php` 的 `sources.acme` 加显示名 | 合同变更 | X2 提、C 定 |
| 5 | 主数据：`/admin/carriers` 新建承运商（code `ACME`）；`carrier_services` 加行：`source = acme` × 每个服务等级（`standard` / `express` / `same_day`）× `default_eta_days`，`active = true`（`TransportSeeder` 或 tinker）。没有对应行的报价会被丢弃 | Transport | X2 |
| 6 | 定价：客户价目表 `TR-DELIVERY-BASE` 的第三方加价规则默认覆盖所有第三方来源；要按承运商单独定价时加带 `carrier_id` 的 `rate_items` | 价目表页 | 财务 |
| 7 | 测试：`tests/Feature/Transport/AcmeAdapterTest.php extends Tests\Support\CarrierAdapterTestCase`，在 `fakeCarrierFor*()` 里用 `Http::fake()` 假造承运商响应；再加 2–3 个自己的用例（单位换算、服务码、失败响应）。`php artisan test --filter=Acme` | tests | X2 |
| 8 | 沙箱验证（第 8 节清单） | — | X2 |
| 9 | 上线：服务器 `.env` 填密钥 → `bash deploy/deploy-trial.sh`（会重建配置缓存）→ 用一张真实运单走完 报价 → 预订 → 面单 → 轨迹 → 取消 | — | C |

## 5. 服务等级与匹配规则

- 一个来源的每个报价方案都要能对上 `carrier_services` 里 `source` 相同、`service_level` 相同且 `active` 的一行，否则被丢弃；`eta_days` 为空且该行也没有 `default_eta_days`，同样丢弃。
- 承运商的服务名（如 `road_express`、`PRIORITY`）在适配器里映射成三个等级之一（模板 `serviceLevel()`）。同一等级多个服务时都返回，ERP 按价格 / 时效标"推荐"。
- `service_code` 是承运商码，ERP 不理解它，只在预订时原样交回。

## 6. 定价

- 适配器给 `cost_cents`（含 GST 的成本）。客户价 = 成本 × 客户价目表加价（或固定价），由 `TransportOptionService::thirdPartyPrice()` 通过 `RateService` 计算；`own_fleet` 走区价，`manual` 用人填的客户价。
- 不要在适配器里加价，不要返回 0 元方案。

## 7. 预订、面单、取消、轨迹的细节

- **预订失败**只用返回值表达（`status` 落在未预订列表里），ERP 会开异常并显示原因；不要 `throw`。
- **幂等**：同一运单不会被预订两次（`ShipmentBookingService` 已锁定），但承运商侧的重试要靠 `reference`（我们的运输单号）去重。
- **面单**：`label()` 返回 PDF 字节；不是 PDF 一律 `null`。`capabilities.label = false` 的来源用 ERP 自己的面单（自有车队那套）。
- **轨迹 poll**：`transport:sync-tracking` 每 30 分钟对已预订 / 已发运 / 运输中的运单调 `tracking()`，只写新事件（按 `occurred_at` + `status` 去重）；连续失败会开异常提醒人工。
- **轨迹 webhook**：目前没有通用回调路由。承运商只推不拉的，需要在 Transport 加一条 `POST /transport/webhooks/{source}` 路由（校验签名、按事件 id 幂等、调用 `TrackingSyncService` 写事件），并把 `capabilities.tracking` 填 `webhook`。这是新增路由，走合同变更。
- **取消**：`cancel()` 只回 `true` / `false`；已出面单能否取消由承运商决定。
- **POD**：`pod = 'api'` 表示签收凭证可从承运商拿（尚无来源用到）；`manual` = 人工上传。

## 8. 测试与沙箱清单

合同测试（`CarrierAdapterTestCase`）对每个适配器检查：`source()` 是小写代号；`capabilities()` 六个键齐全、取值合法；`quote()` 每个方案的键、类型、`cost_cents ≥ 1`、等级合法、日期格式；`book()` 五个键齐全；`tracking()` 事件五个键；`label()` 是 PDF 或 `null`；`cancel()` 是 bool；承运商断网时报价为空、预订为失败、**没有异常**。`ExampleHttpCarrierAdapterTest` 是完整范例。

沙箱验证时逐项打勾：

- [ ] 报价：价格含 GST；服务码在两次请求间稳定；同一请求两个方向（VIC→NSW、NSW→VIC）都有结果；住宅地址、尾板选项有变化。
- [ ] 预订：回来的 `booking_ref` 可以直接拿去查面单和轨迹；失败响应（错邮编、超重）在 ERP 异常里能看到原因。
- [ ] 面单：PDF 能打开，尺寸符合承运商要求（A6 / A4）。
- [ ] 轨迹：至少一条事件能映射到"已发运 / 运输中 / 已送达"；时间带时区。
- [ ] 取消：取消后再查状态是取消。
- [ ] 密钥不在仓库、不在日志（`storage/logs`）里出现；`Http` 超时 ≤ 20 秒。
- [ ] `php artisan test` 全绿，`./vendor/bin/pint` 干净。

## 9. 客户 / 上游系统 → ERP：订单 API

**认证**：管理员在 `/orders/api-tokens` 为某个客户签发令牌（明文只显示一次，库里只存哈希），客户系统每次请求带 `Authorization: Bearer <token>`。令牌只能操作它所属客户的数据。JSON 请求，无 session、无 CSRF。

**A. 单笔订单** — `POST /orders/api/orders`

请求体（JSON）：`order_type`（`from_stock` 库存出库 \| `pickup_deliver` 现场提货直送）、`external_ref`（客户单号，同一客户内唯一）、`consignment_mark`、`fba_reference`、`deliver_to_name`、`deliver_to_phone`、`deliver_to_address`、`deliver_to_suburb`、`deliver_to_state`、`deliver_to_postcode`、`deliver_to_address_type`（`business` \| `residential`）、`requested_date`、`service_level`、`lines[]`（`description_en`、`description_cn`、`package_type`、`carton_qty`、`unit_qty`、`actual_weight_kg`、`length_mm`、`width_mm`、`height_mm`、`cbm`、`asn_line_id`、`storage_tier`）；提货直送另加 `pickup_address{name, phone, address, suburb, state, postcode}` 和 `declared_packages[]`（`package_type`、`qty`、`weight_kg`、`length_mm`、`width_mm`、`height_mm`）。
可选头 `Idempotency-Key: <任意唯一串>`：同 key 重发返回原订单（`replayed = true`），不会重复建单。

响应 `201`：`{ "order_id", "order_no", "operational_status", "customer_status", "replayed" }`。

**B. 整张清单** — `POST /orders/api/imports`（multipart）

字段：`manifest`（CSV / XLSX 文件，≤ 10 MB，格式同客户门户的模板）、`order_type`、`group_by`（按收件人 / 唛头分单）、`address_type_default`、`auto_confirm`（没有错误、每组都就绪时直接确认）、`force`、`container_no`、`container_size`、`expected_date`、提货直送的 `pickup_*`。客户主数据里可以预设这些默认值（管理员 → 客户 → 自动导入）。

响应：`201` 已导入并确认；`202` 已收到、待人工处理（有错误行或需要确认）；`200` 同一文件（sha256 相同）重放，返回原结果。查询进度：`GET /orders/api/imports/{import_id}`。

结果 JSON：`import_id`、`status`、`source`、`order_type`、`file_name`、`replayed`、`auto_confirmed`、`row_count`、`error_count`、`groups{total, ready, blocked, imported}`、`orders[{order_id, order_no, rows}]`。

**错误格式**（两组接口相同）：`401 {"error":"unauthenticated"}`、`403 {"error":"client_inactive"}`、`404 {"error":"not_found"}`、`422 {"error":"validation_failed","errors":{字段: [消息]}}`。

**另一条路**：不改客户系统时，客户把文件放到约定的收件夹（SFTP 目录 `storage/app/imports/inbox/<client>/`），`imports:inbox` 每 5 分钟自动导入，结果写回 `.result.txt` 并发邮件（`deploy/README.md`）。

---

## English quick reference

- **One interface**: `App\Support\Contracts\CarrierAdapter` — `source()`, `capabilities()`, `quote()`, `book()`, `cancel()`, `label()`, `tracking()`. Money in integer **cents** (AUD, GST inclusive), dimensions in **mm**, weight in **kg**. "No quote" is an **empty list**, never an exception; catch `ConnectionException`; never return a $0 option.
- **Template**: copy `app/Modules/Transport/Adapters/ExampleHttpCarrierAdapter.php`; it is not registered in production. Register yours in `TransportServiceProvider` (`transport.carrier-adapters` tag), add the source to `TransportEnums::SOURCES` + `contracts/enums.md` + `lang/zh/transport.php` `sources`, add `carrier_services` rows (source × service level × default ETA), keep credentials in `config/services.php` ← `.env`.
- **Quote option**: `service_code` (echoed back to `book()`), `service_name`, `service_level` ∈ {standard, express, same_day}, `cost_cents` ≥ 1, `eta_days|null`, `pickup_dates[]` (Y-m-d), `raw` (`raw.booking_id` comes back as `$options['quote_ref']`).
- **Booking result**: `booking_ref`, `tracking_number|null`, `label_path|null`, `status` (`''`, `new`, `pending_payment`, `pending_review`, `request_failed`, `cancelled` = not booked), `raw`.
- **Tracking event**: `status` (mapped by keywords: delivered / in transit / picked up / failed), `description`, `location`, `occurred_at` (ISO 8601), `raw`. Polled every 30 minutes by `transport:sync-tracking` when `capabilities.tracking = 'poll'`.
- **Contract test**: extend `Tests\Support\CarrierAdapterTestCase`, fake the carrier with `Http::fake()` in the `fakeCarrierFor*()` hooks; see `ExampleHttpCarrierAdapterTest`.
- **Client → ERP**: bearer token issued at `/orders/api-tokens`; `POST /orders/api/orders` (one order, optional `Idempotency-Key`), `POST /orders/api/imports` (multipart manifest → 201 imported / 202 pending / 200 replay), `GET /orders/api/imports/{id}`; errors `{"error": …}` with 401 / 403 / 404 / 422.
