# 试用指南(本地试用 / 给别人试用)

适用于当前 main(M7)。所有命令都在仓库目录 `~/Desktop/erp-repo` 下执行;终端里先执行一次 `export PATH="/opt/homebrew/bin:$PATH"`。

## 1. 三种试用方式

| 方式 | 适合谁 | 怎么做 |
|---|---|---|
| **A. 在这台 Mac 上自己试** | 你自己 | 第 2 节启动,浏览器打开 http://localhost:8000 |
| **B. 同一 Wi-Fi 的同事试** | 办公室里的人 | 启动时用 `php artisan serve --host=0.0.0.0 --port=8000`,把这台 Mac 的 IP 告诉对方(系统设置 → 网络,例如 `http://192.168.1.23:8000`)。Mac 不能休眠;先按第 6 节改掉默认密码 |
| **C. 外部人员 / 长期试用(现用)** | 客户、外部同事 | 试用服务器已上线:**http://103.6.171.144**(Kamatera 悉尼,Ubuntu 24.04 + nginx + PHP 8.3 + MySQL 8,cron 每分钟跑调度和队列,无守护进程)。账号密码与第 4 节相同;数据于 2026-09-08 从本机试用库整体搬入。合入 main 后同步服务器:`bash deploy/deploy-trial.sh`(推送 main,服务器执行 `deploy/server/deploy.sh`:拉代码、依赖、增量迁移、重建缓存,不重置数据)。SSH:`ssh -i ~/.ssh/erp-oracle root@103.6.171.144`。PDF 中文字体在服务器 `storage/fonts/cjk.ttf`(git 忽略,换机器要重新放)。Karrio 未装在服务器上,第三方运输报价走人工承运商。 |

## 2. 每次启动(方式 A / B)

方式 C 的服务器不需要这些步骤:网站常驻,调度和队列由 cron 每分钟自动执行。

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

试用服务器地址 **http://103.6.171.144/login**(本机开发时为 http://localhost:8000/login)。所有演示账号共用一个密码,由项目负责人另行发送,不写在本文档里(改法见第 6 节)。

| 账号 | 角色 | 看什么 |
|---|---|---|
| admin@erp.local | 管理员 | 全部;用户管理、审批中心、审计日志 |
| customer-service@erp.local | 客服 / Coordinator | 订单、预报单 (ASN)、待收货列表(只看)、调度队列 |
| dispatcher@erp.local | 调度 | 运输报价、订舱、班次 |
| warehouse-supervisor@erp.local | 仓库主管 | 收货 / 无预报收货、入库单、上架、出库、盘点、退货验收 |
| warehouse-operator@erp.local | 仓库操作员 | 同上(无配置权限) |
| transport-operator@erp.local | 司机 | 司机手机页 /driver |
| finance@erp.local | 财务 | 计费、发票、收款、财务锁、价目表审批 |
| client@erp.local | 客户(Edward) | 客户门户 /portal,只看自己的数据 |

**客户自助注册(反馈 #8):** 登录页有"注册新客户"链接(/register)。填公司名、ABN、联系人、地址、邮箱、密码后提交,状态为"待审核";管理员 / 客服 / 财务在 /admin/clients 看到待审核客户排在最前,点"审核通过"后该公司即可登录客户门户,只看到自己的订单、库存和发票,发票 Bill-to 用注册时的公司名 / ABN / 地址。不想开放注册时在 `.env` 设 `ALLOW_SIGNUP=false`。

**客户下单两步走(反馈 #10):** 客户门户"新建订单"先点"获取估价",页面显示各项仓库费用(客户价、含 GST 合计;运费在提交后另行报价),确认无误再点"确认提交订单";改动任何内容后需重新获取估价。

## 5. 页面地图

| 模块 | 入口 | 主要页面 |
|---|---|---|
| Job 工作台 | /jobs | 每个 Job 一页:订单、入库、库存、发运、单据、发票、费用与毛利 |
| 平台 | /admin/… | 用户 /admin/users、异常中心 /admin/exceptions、审批 /admin/approvals、审计日志 /admin/activity、单据中心 /admin/documents、集成监控 /admin/integration、全局搜索 /admin/search |
| 主数据 | /admin/clients | 客户、承运商 /admin/carriers、供应商 |
| 订单 | /orders | 新建 /orders/create、Excel 导入 /orders/imports、PDF 读单 /orders/drafts/create、调度队列 /orders/queue、入库批次 /orders/batches、地址簿 /orders/addresses、API 钥匙 /orders/api-tokens |
| 仓库 | /warehouse | 预报单 (ASN) /warehouse/asns、收货(待收列表)/warehouse/receiving、无预报收货 /warehouse/receiving/unplanned、入库单 /warehouse/receipts、上架 /warehouse/putaway、出库 /warehouse/outbound、退货 /warehouse/returns、任务 /warehouse/tasks、盘点 /warehouse/stocktakes、扫码 /warehouse/scan、快照 /warehouse/snapshots、库位配置 /warehouse/config/locations |
| 运输 | /transport | 运单列表(报价 / 订舱 / 面单 / 签收)、班次 /transport/runs、承运商账单对账 /transport/carrier-invoices、司机页 /driver |
| 计费 | /billing | 待开票 /billing/unbilled、待审核 /billing/charges/review、发票 /billing/invoices、应收 /billing/receivables、价目表 /billing/rate-cards、收费项 /billing/charge-codes、客户报价单 /billing/quotes |
| 报表 | /reports | 老板视角;/reports/client 按客户看 |
| 客户门户 | /portal | 我的订单、下单、估价、确认运输方案、我的库存 /portal/stock、我的账单 /portal/invoices、我的报表 /portal/reports |

## 6. 给别人试用前

1. **改默认密码**:本机上在 `.env` 里加一行 `SEED_DEMO_PASSWORD=你的密码`,再执行第 3 节的重置命令(所有演示账号都会用新密码)。服务器上不要重置数据,用 admin 登录 /admin/users 逐个编辑用户改密码即可。
2. Karrio 后台(3002 端口)只给自己用,不要开放给别人。
3. 演示数据里的清单是去标识版本,可以给人看。
4. 手机扫码页(/warehouse/scan)用摄像头需要 HTTPS 或 localhost;方式 B 的局域网地址和方式 C 的服务器目前都是 http,只能用扫码枪 / 手动输入,服务器配上域名和 HTTPS 后即可用摄像头。

## 7. 手动走一遍(推荐顺序,20 分钟)

先用第 3 节的空白系统,或在演示数据基础上新建。括号里是建议登录的账号。

1. **入库**(warehouse-supervisor):/warehouse/asns/create 新建预报单 (ASN)(整柜)→ 进入预报单页 → "Excel 导入" 上传 `data/需派送货物清单.xlsx` → 逐行"收货"(或用顶栏"收货"的待收货列表;试着少收 2 箱并填原因,看 /admin/exceptions 出现差异)。收的行自动归到一张入库单(编号 = 预报单号-R1;下次到货再收的行会开 -R2)→ 全部收完后在预报单页或入库单页点"入库完成"→ "打印入库单 (PDF)"(单据中心 /admin/documents 可下载;客户门户暂无入口,见 HANDOFF 的 X1 待办)→ /warehouse/putaway 输入库位码上架 → 预报单页点"从预报单 (ASN) 生成派送订单"。
   - **1b. 无预报收货**(warehouse-operator):货到了但没有预报单时,顶栏"无预报收货"(/warehouse/receiving/unplanned):选客户、仓库、送货参考、收货库位,逐行填唛头 / 品名 / 实收 / 破损 / 托盘数,提交即生成临时预报单和一张已完成的入库单;上架前 customer-service 在预报单页点"确认无预报到货"。
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
| ASN 和入库单有什么区别 | **预报单 (ASN)** 是货到之前的预报(客户 / 客服告诉仓库将有什么货来);**入库单**是仓库实际收到货以后的凭证,一次到货一张(编号 = 预报单号-R1、-R2…),写实收 / 破损 / 差异并可打印签字。一张预报单可以对应多张入库单,页面上会汇总 |
| 客服账号为什么看不到收货按钮 | 收货、无预报收货、入库完成是仓库操作(admin / warehouse-supervisor / warehouse-operator);客服 (customer-service) 能看预报单、待收货列表和入库单,但不能录实收。用 warehouse-operator 账号操作 |
| 点"入库完成"报"未配置中文 PDF 字体" / 预览 PDF 里中文是空白 | dompdf 自带字体没有中文,系统在没有中文字体时拒绝完成入库单(否则存档 PDF 会是空白中文)。**每个代码目录 / 每台部署机器**都要放一次:把一个中文 TrueType 字体复制到该目录的 `storage/fonts/cjk.ttf`(这台 Mac:`cp "/System/Library/Fonts/Supplemental/Arial Unicode.ttf" storage/fonts/cjk.ttf`;Linux 服务器可用 Noto Sans CJK),或在 `.env` 里用 `PDF_CJK_FONT` 指向项目内的字体文件,然后重试 |
| 报错 `Connection refused` / 数据库连不上 | `brew services start mysql@8.4` |
| 端口 8000 被占 | `php artisan serve --port=8001`,并把 `.env` 的 `APP_URL` 改成对应端口 |
| 运输报价没有 Karrio 方案 | Docker Desktop 未启动或容器未起;`.env` 里 `KARRIO_API_KEY` 为空;Karrio 里没有承运商费率(`php docker/karrio/setup-demo-carrier.php` 可重建演示承运商) |
| 想看发出的邮件 | 本地邮件写在 `storage/logs/laravel.log`(`MAIL_MAILER=log`) |
| API 试用 | `curl -X POST http://103.6.171.144/orders/api/orders -H "Authorization: Bearer <钥匙>" -H "Idempotency-Key: demo-1" -H "Content-Type: application/json" -d @order.json`(钥匙在 /orders/api-tokens 生成,只显示一次) |
