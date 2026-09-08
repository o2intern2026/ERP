# 试用指南(本地试用 / 给别人试用)

适用于当前 main(M7)。所有命令都在仓库目录 `~/Desktop/erp-repo` 下执行;终端里先执行一次 `export PATH="/opt/homebrew/bin:$PATH"`。

## 1. 三种试用方式

| 方式 | 适合谁 | 怎么做 |
|---|---|---|
| **A. 在这台 Mac 上自己试** | 你自己 | 第 2 节启动,浏览器打开 http://localhost:8000 |
| **B. 同一 Wi-Fi 的同事试** | 办公室里的人 | 启动时用 `php artisan serve --host=0.0.0.0 --port=8000`,把这台 Mac 的 IP 告诉对方(系统设置 → 网络,例如 `http://192.168.1.23:8000`)。Mac 不能休眠;先按第 6 节改掉默认密码 |
| **C. 外部人员 / 长期试用** | 客户、外部同事 | 需要一台服务器(PHP 8.2+、MySQL 8、可跑 cron)。步骤:克隆仓库 → `composer install --no-dev` → 复制 `.env.example` 为 `.env` 并设置 `APP_ENV=production`、`APP_DEBUG=false`、`APP_URL`、数据库、`SEED_DEMO_PASSWORD`、`USE_FAKE_SERVICES=false`、邮件服务 → `php artisan key:generate && php artisan migrate --seed` → 用 Nginx/Apache 指向 `public/` → 系统 cron 每分钟跑 `php artisan schedule:run`。这一步我可以在你有服务器时直接做 |

## 2. 每次启动(方式 A / B)

1. **数据库**:`brew services start mysql@8.4`(已在跑就跳过)。
2. **网站**(终端 1):
   ```bash
   php artisan serve
   ```
3. **事件分发**(终端 2,必须开着!否则"订单确认 → 预留库存"、"打包 → 运输报价"这类跨模块动作不会发生):
   ```bash
   php artisan schedule:work
   ```
   不想开第二个终端时,也可以每做完一步手动执行一次 `php artisan outbox:dispatch`。
4. **Karrio 承运商网关**(可选,想看第三方报价 / 订舱 / 面单时才需要):打开 Docker Desktop,然后
   ```bash
   docker compose -f docker/karrio/docker-compose.yml --env-file docker/karrio/.env up -d
   ```
   Karrio 后台在 http://localhost:3002。不开它,运输报价页只会出现自有车队和人工录价两种方案。

## 3. 演示数据

一条命令把整条业务链路跑出来(约 5 秒),适合"先看看系统长什么样":

```bash
php artisan migrate:fresh --seed && php artisan db:seed --class=DemoFlowSeeder
```

它会重建数据库并生成:Edward 的 40 尺整柜(真实清单 135 行)→ 收货(含短少、破损)→ 上架 → 按唛头生成 30 张订单 → 4 张走完波次 / 拣货 / 打包 → 自有车队签收、一单派送失败再派、一单第三方(Karrio 在线则走 Karrio,否则人工报价)、一单被财务锁住;退货验收;9 天库存快照与周仓储费;服务发票(已部分收款)、仓储发票、月结客户合并发票;一个给 Edward 的 API 钥匙(命令输出里显示一次)。

只想要空白系统自己从头录:只执行 `php artisan migrate:fresh --seed`(有 8 个账号、3 个客户、仓库 / 库位、价目表、承运商,没有业务单据)。

> 重置 = 再跑一次上面的命令。数据库里的东西会全部清掉。

## 4. 登录账号

地址 http://localhost:8000/login 。密码统一为 `password`(改法见第 6 节)。

| 账号 | 角色 | 看什么 |
|---|---|---|
| admin@erp.local | 管理员 | 全部;用户管理、审批中心、审计日志 |
| customer-service@erp.local | 客服 / Coordinator | 订单、入库 ASN、调度队列 |
| dispatcher@erp.local | 调度 | 运输报价、订舱、班次 |
| warehouse-supervisor@erp.local | 仓库主管 | 收货、上架、出库、盘点、退货验收 |
| warehouse-operator@erp.local | 仓库操作员 | 同上(无配置权限) |
| transport-operator@erp.local | 司机 | 司机手机页 /driver |
| finance@erp.local | 财务 | 计费、发票、收款、财务锁、价目表审批 |
| client@erp.local | 客户(Edward) | 客户门户 /portal,只看自己的数据 |

## 5. 页面地图

| 模块 | 入口 | 主要页面 |
|---|---|---|
| Job 工作台 | /jobs | 每个 Job 一页:订单、入库、库存、发运、单据、发票、费用与毛利 |
| 平台 | /admin/… | 用户 /admin/users、异常中心 /admin/exceptions、审批 /admin/approvals、审计日志 /admin/activity、单据中心 /admin/documents、集成监控 /admin/integration、全局搜索 /admin/search |
| 主数据 | /admin/clients | 客户、承运商 /admin/carriers、供应商 |
| 订单 | /orders | 新建 /orders/create、Excel 导入 /orders/imports、PDF 读单 /orders/drafts/create、调度队列 /orders/queue、入库批次 /orders/batches、地址簿 /orders/addresses、API 钥匙 /orders/api-tokens |
| 仓库 | /warehouse | 入库 ASN /warehouse/asns、上架 /warehouse/putaway、出库 /warehouse/outbound、退货 /warehouse/returns、任务 /warehouse/tasks、盘点 /warehouse/stocktakes、扫码 /warehouse/scan、快照 /warehouse/snapshots、库位配置 /warehouse/config/locations |
| 运输 | /transport | 运单列表(报价 / 订舱 / 面单 / 签收)、班次 /transport/runs、承运商账单对账 /transport/carrier-invoices、司机页 /driver |
| 计费 | /billing | 待开票 /billing/unbilled、待审核 /billing/charges/review、发票 /billing/invoices、应收 /billing/receivables、价目表 /billing/rate-cards、收费项 /billing/charge-codes、客户报价单 /billing/quotes |
| 报表 | /reports | 老板视角;/reports/client 按客户看 |
| 客户门户 | /portal | 我的订单、下单、估价、确认运输方案、我的库存 /portal/stock、我的账单 /portal/invoices、我的报表 /portal/reports |

## 6. 给别人试用前

1. **改默认密码**:在 `.env` 里加一行 `SEED_DEMO_PASSWORD=你的密码`,再执行第 3 节的重置命令(所有演示账号都会用新密码)。
2. Karrio 后台(3002 端口)只给自己用,不要开放给别人。
3. 演示数据里的清单是去标识版本,可以给人看。
4. 方式 B 时手机扫码页(/warehouse/scan)用摄像头需要 HTTPS 或 localhost,局域网 http 地址下只能用扫码枪 / 手动输入。

## 7. 手动走一遍(推荐顺序,20 分钟)

先用第 3 节的空白系统,或在演示数据基础上新建。括号里是建议登录的账号。

1. **入库**(warehouse-supervisor):/warehouse/asns/create 新建整柜 ASN → 进入 ASN 页 → "Excel 导入" 上传 `data/需派送货物清单.xlsx` → 逐行"收货"(试着少收 2 箱并填原因,看 /admin/exceptions 出现差异)→ /warehouse/putaway 输入库位码上架 → ASN 页点"从 ASN 生成派送订单"。
2. **订单**(customer-service):/orders 看到刚生成的订单 → 打开一张 → "确认"。等一分钟(或跑 `php artisan outbox:dispatch`),/warehouse/reservations 出现预留,/transport 出现运单和初步报价。
3. **出库**(warehouse-operator):/warehouse/outbound → 勾选订单"释放波次" → 进波次页逐行"确认"拣货(试一次少拣,看 Pick Short 异常)→ "打包" 录包裹重量尺寸(录一个 25kg 以上的看尾板)→ 回到出库页。
4. **运输**(dispatcher):/transport 打开该运单 → 方案列表(自有车队 / Karrio / 人工录价)→ 选一个 → "订舱"。自有车队:/transport/runs/create 建今天的班次、加入运单;然后 warehouse-operator 在 /warehouse/outbound "发运交接"。
5. **司机签收**(transport-operator,手机或电脑):/driver → 选停靠点 → 签名 + 拍照 → 送达。订单变"已送达";签收邮件内容在 `storage/logs/laravel.log`。
6. **财务锁**(finance):任一已确认订单页 → 置财务锁 → 仓库试发运会被拒 → 放行后再试。
7. **计费**(finance):/billing/unbilled 看逐条费用(拆柜、上架、订单处理、拣货、运费、尾板);Job 页点进对应 Job 看毛利;/billing/invoices 生成 / 开出发票,登记收款。
8. **客户视角**(client):/portal 只看到 Edward 的订单;下单、估价、确认运输方案、看库存、下载发票。
9. **退货**(warehouse-supervisor + finance):订单页发起退货 → /warehouse/returns 登记收货 → 验收判定去向 → 财务决定。
10. **平台**(admin):/admin/exceptions 三类异常同列;/admin/approvals 价目表审批;/admin/activity 谁改了什么;/admin/integration 事件分发情况。

## 8. 常见问题

| 现象 | 处理 |
|---|---|
| 页面能开,但确认订单后没有预留 / 报价 | 事件分发没跑:开 `php artisan schedule:work`,或手动 `php artisan outbox:dispatch` |
| 报错 `Connection refused` / 数据库连不上 | `brew services start mysql@8.4` |
| 端口 8000 被占 | `php artisan serve --port=8001`,并把 `.env` 的 `APP_URL` 改成对应端口 |
| 运输报价没有 Karrio 方案 | Docker Desktop 未启动或容器未起;`.env` 里 `KARRIO_API_KEY` 为空;Karrio 里没有承运商费率(`php docker/karrio/setup-demo-carrier.php` 可重建演示承运商) |
| 想看发出的邮件 | 本地邮件写在 `storage/logs/laravel.log`(`MAIL_MAILER=log`) |
| API 试用 | `curl -X POST http://localhost:8000/orders/api/orders -H "Authorization: Bearer <钥匙>" -H "Idempotency-Key: demo-1" -H "Content-Type: application/json" -d @order.json`(钥匙在 /orders/api-tokens 生成,只显示一次) |
