# Codex 席位执行计划(X1 · X2)

> 给两位用 Codex 的组员。读这一份就够开工;规则细节在 `AGENTS.md`(Codex 每次会话自动读),业务细节在 `ERP_PLAN.md` 对应章节,协作机制在 `COLLAB_PLAN.md`。
> 版本:v1.1 · 2026-09-07 · 维护:席位 C · **本版按"WMS / TMS 先行"重排:X2 只做 TMS(WMS 改由 C 做),X1 先做 WMS / TMS 依赖的最小 OMS,门户与报表推后到 M6。**

---

## 0. 你是谁

| 席位 | 模块目录 | 路由前缀 | 业务章节 | 验收清单 |
|---|---|---|---|---|
| **X1** | `app/Modules/Orders`、`Portal`、`Reports` | `/orders` `/portal` `/reports` | ERP_PLAN §3(OMS)、§2 门户与报表部分 | §3.8 |
| **X2** | `app/Modules/Transport` | `/transport` `/driver` | ERP_PLAN §5(TMS) | §5.7 |

仓库模块(`Warehouse`,ERP_PLAN §4)由席位 C 直接做;X2 不再负责 WMS。

你只能改自己模块目录、自己的 `lang/zh/<module>.php`、`tests/Feature/<Module>/`、`resources/views/layouts/nav/<module>.blade.php`,以及 `ERP_PLAN.md` 任务表里自己的行、`DatabaseSeeder` 里自己的 `call` 行。其他一切(`contracts/`、`config/`、`composer.json`、`app/Support/`、别人的模块)**不碰**,要改就写进 `contracts/CHANGE_REQUESTS.md`。

---

## 1. 第 0 天:装环境(免费,约 30 分钟)

1. **PHP 8.2+ / Composer / MySQL 8**
   - Windows:装 [Laragon](https://laragon.org)(自带 PHP、MySQL、Composer)。
   - macOS:装 [Laravel Herd](https://herd.laravel.com)(免费版)+ [DBngin](https://dbngin.com)(免费 MySQL),或 Homebrew `brew install php composer mysql`。
   - 不要用 SQLite,不要用 Docker。
2. **Node.js 18+**(只为装 Codex CLI):`npm install -g @openai/codex`,然后 `codex login`(用你的 ChatGPT 账号)。也可以用 VS Code 的 Codex 扩展,效果相同。**不要用网页版 Codex 云任务**跑开发——云端沙箱连不到你本地的 MySQL,测试跑不了。
3. **席位身份文件**(不在仓库里,只在你自己电脑):创建 `~/.codex/AGENTS.md`,内容二选一:

   ```markdown
   SEAT=X1
   I am seat X1 of the Logistics ERP project. I own app/Modules/{Orders,Portal,Reports}, lang/zh/{orders,portal,reports}.php, tests/Feature/{Orders,Portal,Reports}/, and the nav includes for those modules. I never edit any other directory, contracts/, or the frozen zone. I work only on branches named block/x1-*. I never push to main.
   ```

   ```markdown
   SEAT=X2
   I am seat X2 of the Logistics ERP project. I own app/Modules/{Warehouse,Transport}, lang/zh/{warehouse,transport}.php, tests/Feature/{Warehouse,Transport}/, and the nav includes for those modules. I never edit any other directory, contracts/, or the frozen zone. I work only on branches named block/x2-*. I never push to main.
   ```

4. **Codex 设置**(`~/.codex/config.toml`):沙箱 `workspace-write`;审批模式选需要确认的那档,让它执行 `git push`、`composer require` 前先问你。
5. **拉代码、跑通测试**:

   ```bash
   git clone <repo-url> erp && cd erp
   composer install
   cp .env.example .env && php artisan key:generate     # 只改 DB_USERNAME / DB_PASSWORD
   php artisan migrate:fresh --seed
   php artisan test                                      # 必须全绿
   ```

6. **读三样东西**(不要读整份 ERP_PLAN,16 万字符,烧额度):`AGENTS.md` 全文;`ERP_PLAN.md` 的 §0 和你的模块章节;`contracts/` 里你模块会用到的文件(enums、events、services、db-schema)。有疑问写进 `contracts/CHANGE_REQUESTS.md`,不要自己猜。

M0(骨架 + 契约)由 C 完成前,你能做的到此为止。M0 合入后 C 会通知。

---

## 2. 每次会话的固定动作

```bash
# 开工
git fetch --all --prune
git checkout block/<你的当前版块> && git pull --rebase
composer install
php artisan migrate:fresh --seed && php artisan test      # 绿了再开工

# 给 Codex 的第一句话(模板)
# "Read AGENTS.md. Implement task <ID> exactly as described in ERP_PLAN.md §<x.y> '<ID> · …'
#  and its data model in §<x.2>. Use contracts/enums.md, events.md, services.md verbatim.
#  Deliver migration + pages + lang/zh entries + Feature tests covering acceptance items §<x.7> #<n>, #<m>.
#  Do not touch files outside app/Modules/<Module>. Stop when tests are green."

# 收工(每次会话都要,哪怕没做完)
php artisan test && ./vendor/bin/pint
git add -A && git commit -m "x1/oms: A3 order model + state machine"     # 或 "WIP: A3 — 剩时间线页"
git push origin block/<你的当前版块>
# 在 ERP_PLAN.md 任务表打勾
```

**铁律:一次会话只做一个任务编号;做完就 push;额度快用完时先 push WIP 再停。**

---

## 3. X1 执行顺序

### 版块 1 · `block/x1-oms-min`(M1 合入后开工,目标检查点 **M3**)

这是 WMS 出库与 TMS 派送的输入,**M3 卡在你这里**,所以只做最小集、按顺序推。

| 顺序 | 任务 | 依赖 / 阻塞时怎么办 | 交付物 | 验收(§3.8) |
|---|---|---|---|---|
| 1 | **A3** 订单模型 + 四维状态机 + 列表 / 详情 / 时间线 + 手工建单 | M1 的 clients、jobs、JobService | orders、order_lines、fulfilments、holds、order_events 迁移;状态映射表;列表 / 详情 / 时间线页;`OrderService::createFromAsn` 按 contracts/services.md 签名实现(C 的 B2c 要调) | #3、#4 |
| 2 | **A17** 客户收件地址簿 | A3 | client_addresses 用法(表归 C 的 MasterData,你只读写自己的关联) | #11 |
| 3 | **A4** Excel 导入(唛头分组、行级报错、去重) | A3;解析器与 C 的 B2b 共用 → 放在 Orders 模块,按 contracts/services.md 的 `ManifestParser` 接口暴露 | 导入页、导入记录表、错误行报表 | #1、#2 |
| 4 | **A7** 在库校验 + 自动拆出可发部分 + 履约批次 | 库存来自 C 的 B1 → M2 前用 `FakeStockService` | 履约批次页;`order.confirmed` / `order.cancelled` / `order.reduced` 事件按 contracts/events.md 发出(WMS 预留靠它) | #8 |
| 5 | **A14** 入库批次关联 | A3;asns 表归 C → 只读 | 订单 ↔ ASN 关联与"按柜号查全部订单"页 | #9 |
| 6 | **A16** 尾板车自动判定 | 阈值来自客户价目表(C 的 A5,M6)→ 先用 `FakeRateService` 返回默认 25 kg | 确认订单时写 `tailgate_required`,人工覆盖填原因 | #10(前半) |
| 7 | **A11b** 纯运输订单 | A3 | 不经仓库直接进 TMS 待派(X2 的 TMS 联调靠它) | #6 |
| 8 | **A15** 协调员 队列 | A3;异常表归 Platform → 经 `ExceptionService` 写 | 队列页(按类型 / 负责人筛) | — |

版块完成标准:8 个任务全打勾,`php artisan test` 全绿,CI 绿 → 开 PR `block/x1-oms-min → main`,标题 `M3: OMS minimal block`,描述按 §5 模板。等 C 合并后 `git rebase main`。

**M3 之后到 M6 之前**你有空档:优先按 §6 的代工规则协助 X2 的 TMS(在 `block/x2-tms` 上,登记 `HANDOFF.md`,同一时间只能一人写),或提前做版块 2 里不依赖 Billing 的任务(A11、A12、A4b)。

### 版块 2 · `block/x1-portal-reports`(M5 合入后开工,目标检查点 **M6**,在 C 的 billing 与 platform-rest 之后合并)

| 顺序 | 任务 | 依赖 | 验收 |
|---|---|---|---|
| 1 | **A13** 财务锁 / 放行(仅人工) | A3 | §3.8 #7 |
| 2 | **A7b** 客户报价单 + 初步估价 | 调 `TransportOptionService`(X2,M5 已合入)与 `RateService`(C,M6)→ RateService 先 Fake | — |
| 3 | **A11** 改单 / 取消权限 + 退货全链路 | 退货验收事件来自 C 的 B13(M4 已合入) | §3.4 退货链路 |
| 4 | **A9-p** 门户下单 + 门户查单 | A3、A1 | §3.8 #5 |
| 5 | **A12** PDF / 邮件读单(上传 → 人工确认草稿单) | A3 | 解析准确率不作验收 |
| 6 | **A4b** 订单 API 接入(接口预留) | A3 | — |
| 7 | **A21** 报表:老板视角 + 客户视角 | 跨模块只读;M5 后有真实数据 | ERP_PLAN §2 报表条目 |
| 8 | **A22** 定时客户报表(邮件) | A21;Scheduler,本地 `schedule:work` 测 | 同上 |

---

## 4. X2 执行顺序

只有一个版块:`block/x2-tms`(M1 合入后开工,目标检查点 **M5**)。分支可以早于 M4 就绪,C 会在 WMS 出库(M4)合入后合它;等待期间做 B8 / B9,或把 Manual 流程按 §5.7 验收再过一遍。

| 顺序 | 任务 | 依赖 / 阻塞时怎么办 | 交付物 | 验收(§5.7) |
|---|---|---|---|---|
| 1 | **B5** shipment 结构 + 状态机 + transport_quotes + consignment note | M1 的 jobs、Outbox 发布器;订单 M3 前用测试数据;包裹 M4 前用 Fake `packages`(按 contracts/db-schema.md 的字段) | shipments、transport_quotes 迁移;状态机;consignment note PDF(dompdf) | #1(结构部分) |
| 2 | **B5c** `TransportOptionService` + `CarrierAdapter`:**先做 Manual 适配器**(人工录报价、tracking no、上传 POD);Transdirect / EIZ 等 C 的 B5e 结论写进 `contracts/carriers.md` 后再做 | B5;沙箱 key 从团队密码库取 | 接口按 contracts/services.md 签名;Manual 全流程可跑 | #3(第三方部分等 B5e) |
| 3 | **B5d** 方案选择:Recommended / Cheapest / Fastest 标记;门户确认或改选;协调员 代选 | B5c;`shipment.quote_confirmed` 事件是运费与尾板费的**唯一产生点**,payload 按 contracts/events.md 带全(运费客户价、tailgate_required、zone) | 方案页;事件 | #1、#2 |
| 4 | **B5b** 班次编排(自派:有序停靠点、指派司机) | B5 | delivery_runs、run_stops;班次页 | #4(前半) |
| 5 | **B6** 自有 label 打印(自派用;第三方用平台 waybill) | B5;`barryvdh/laravel-dompdf` + `picqer/php-barcode-generator`(已在 contracts/dependencies.md) | label PDF | — |
| 6 | **B7** 司机网页表单 + POD(签名 / 拍照 / 失败原因) | B5b;签名 canvas 是唯一允许的第二个 CDN 库;手机浏览器可用 | `/driver` 页;`delivery.pod_captured` 事件 | #4 |
| 7 | **B8** 状态回写(API 自动 + 司机页)+ 异常列表 + POD 邮件 + `delivery.extra_charge` 事件 | B5c、B7 | 异常列表;邮件;事件 | #5、#6 |
| 8 | **B9a** 成本记录:第三方自动取报价成本,自派人工填;每票毛利 | B5c | carrier_costs;订单详情"收 − 付 = 毛利"(客户角色不可见) | #7 |
| 9 | **B9b** 承运商账单对账(导入 + 与报价成本比对) | B9a | 对账页,差异可导出 | — |

版块完成标准:9 个任务全打勾,`php artisan test` 全绿,CI 绿 → 开 PR `block/x2-tms → main`,标题 `M5: TMS block`。

**联调节点:** M4 合入后立刻 `git rebase main`,把 Fake packages 换成真的 `outbound.packed` 事件与 `packages` 表,跑一遍 §5.7 #1、#2。

---

## 5. 每个任务的完成定义(DoD)与 PR 模板

任务算完成,五样都要有:**迁移**(如有)+ **页面**(Blade,走 lang/zh,无硬编码中文)+ **Feature 测试**(覆盖该任务对应的验收条目)+ **lang 条目** + **ERP_PLAN 任务表打勾**。然后 `php artisan test` 绿、`pint` 过、push。

检查点 PR 描述模板:

```markdown
## Block
block/x2-tms → main (M5)

## Tasks
- [x] B5 …  - [x] B5c …  - [x] B5d …  - [x] B5b …  - [x] B6 …  - [x] B7 …  - [x] B8 …  - [x] B9a …  - [x] B9b …

## Tables added / changed
shipments, transport_quotes, delivery_runs, run_stops, pods, carrier_costs, …

## Events emitted (contracts/events.md)
shipment.quote_confirmed, shipment.booked, delivery.pod_captured, delivery.extra_charge

## Fakes still in place (to be swapped after which checkpoint)
FakeRateService (M6)

## Contract change requests
see contracts/CHANGE_REQUESTS.md #4, #5

## Tests
php artisan test: 87 passed · CI: green
```

C 合并时会跑 Integrator:越界文件、契约不一致、测试红 → 退回,不会替你改。

---

## 6. 卡住了怎么办

| 情况 | 做法 |
|---|---|
| 需要的 Service / 事件在别人模块,还没合并 | 按 `contracts/services.md` / `events.md` 写 Fake 或测试事件,继续;PR 里列出 |
| 觉得契约写错了或缺字段 | 追加到 `contracts/CHANGE_REQUESTS.md`(任务号、问题、建议),用最接近的现有定义先做;C 在检查点统一处理 |
| 需要新的 Composer 包 | 同上,写进 CHANGE_REQUESTS;不要自己 `composer require` |
| ERP_PLAN 与 contracts 打架 | **contracts 为准**,并登记差异 |
| 额度用完 | `git commit -m "WIP: <任务> — 剩 …"` → push → 停。C 看到 WIP 可按 `HANDOFF.md` 接手 |
| 测试在你机器绿、CI 红 | 通常是 MySQL 版本或时区;看 CI 日志,别改测试让它过 |
| 想读整份 ERP_PLAN 找上下文 | 不要。找 C 问一句更省 |

---

## 7. 时间线(顺序,不是日期)

```
M0  C:骨架 + 契约          X1 / X2:装环境、通读、写 CHANGE_REQUESTS
M1  C:平台最小集           ── 合入后三线并行 ──
B5e C:承运商 Go / No-Go     X2 据此定 B5c 范围(结论前只做 Manual)
     C:block/c2-wms-core    X2:block/x2-tms            X1:block/x1-oms-min
M2  C WMS 核心 ─► 合入       X1 换真 StockService
M3  X1 OMS 最小集 ─► 合入    C 开 WMS 出库;X2 有纯运输订单可派
M4  C WMS 出库 ─► 合入       X2 rebase,用真 outbound.packed 联调报价
M5  X2 TMS ─► 合入          ★ WMS + TMS 全链路可演示(费用事件已发,尚未计价)
M6  C billing ─► C platform-rest ─► X1 portal-reports 依次合入
之后 只留 fix/* 分支,三方按 ERP_PLAN §7 端到端脚本验收
```
