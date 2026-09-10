# Logistics ERP — 协作计划 v1.1(1 × Claude Max + 2 × Codex,本地免费环境;WMS / TMS 优先)

> v1.1 变更(2026-09-07):开发重点定为 **WMS 与 TMS 先行**。Claude Max 席位(C)从 Billing 改为直接做 WMS;X2 只做 TMS;X1 先做 WMS / TMS 依赖的最小 OMS;Billing、门户、报表、平台附属功能后移到 M6。检查点顺序相应重排。
> 日期:2026-09-07 · 配套文件:`ERP_PLAN.md` v4.7(业务与数据模型不变)
> 本文件**取代** ERP_PLAN.md 的 §8.2 归属矩阵、§8.5 分支与检查点、§8.6 会话仪式、§8.7 指令文件模板、§8.8 Integrator 中与"两个账号 A / B"绑定的部分;§8.1 技术栈、§8.3 目录结构、§8.4 契约文件、§8.9 风险照旧。
> 业务规则、数据模型、任务编号(A0…A31、B1…B14)全部沿用 ERP_PLAN.md,只是**换了谁来做、在哪台机器做、怎么合并**。

---

## 0. 变了什么,没变什么

| | 原计划(ERP_PLAN §8) | 现在 |
|---|---|---|
| 开发席位 | 2 个 Claude 账号(A 前段 / B 后段) | **3 个席位**:C = Claude Max(Claude Code);X1、X2 = 两个 Codex 账号(ChatGPT Plus 档) |
| 同步方式 | GitHub 私有仓库 | 不变 |
| 生产环境 | 后续购买 cPanel 虚拟服务器 | **暂不买**;全部在各自电脑本地跑,免费软件 |
| 技术栈 | PHP 8.2 + Laravel 12 + MySQL 8 + Blade | **不变**(全部免费开源;本地跑没有任何付费依赖) |
| 部署约束 | 无常驻进程 / cron / MySQL / SMTP | **保留**。虽然现在没有服务器,代码仍按虚拟主机约束写,以后买了服务器零改造 |
| 指令文件 | `CLAUDE.md` | `AGENTS.md`(Codex 读)为主本,`CLAUDE.md` 引用它 |
| 合并负责人 | Integrator sub-agent(任一账号) | **固定由 C 执行**,X1 / X2 只开 PR |
| 开发顺序 | 平台 → 库存入库 → OMS → 出库运输 → Billing | **平台(最小)→ WMS 核心 → OMS(最小)→ WMS 出库 → TMS → Billing / 门户 / 报表** |

**结论先行:可行,而且比原方案并行度更高**(三条流水线代替两条)。技术栈不用动一行;需要新设计的只有三件事:三方目录归属、三方检查点顺序、Codex 额度管理。下面逐项展开。

**WMS / TMS 优先意味着什么:** 最强席位(C)直接做 WMS —— 库存流水、预留加锁、快照是全系统最容易做错、返工最贵的部分;TMS 单独给 X2,承运商 API 调研(B5e)由 C 先做完再交给 X2 实现;OMS 只先做 WMS 出库需要的最小部分;Billing 引擎推后,但 WMS / TMS 的**计费事件从第一天就按 contracts/events.md 发**,Billing 上线时不用回头改仓库代码。M5 完成时的可演示目标:**收货 → 上架 → 订单 → 预留 → 拣货 → 打包 → 报价 / 预订 → 班次 → 签收**,全链路跑通,费用事件已发出但尚未计价。

---

## 1. 席位与分工

### 1.1 三个席位

| 席位 | 工具 | 定位 | 为什么这样分 |
|---|---|---|---|
| **C** | Claude Max · Claude Code | 架构、契约、平台基础(最小)、**WMS 仓库模块**、承运商 API 调研、检查点合并与评审;WMS 完成后做 Billing | 最强模型 + 最高额度放在优先级最高、最难做对的模块上:库存流水、预留加锁、快照、任务化作业 |
| **X2** | Codex(账号 2) | **TMS 运输模块** | 数据模型与流程在 ERP_PLAN §5 已到字段级;承运商适配器的范围由 C 的 B5e 结论定死后照单实现 |
| **X1** | Codex(账号 1) | 先做 WMS / TMS 依赖的**最小 OMS**(订单模型、预留事件、从 ASN 关联),再做门户与报表 | 订单是 WMS 出库与 TMS 报价的输入,必须早于 WMS 出库合入;门户 / 报表页面型工作后置 |

### 1.2 目录归属(取代 ERP_PLAN §8.2 / §8.3 的 A / B 标记)

```text
erp/
├── AGENTS.md                        [冻结区 · C]   主指令文件(Codex 读)
├── CLAUDE.md                        [冻结区 · C]   一行 @AGENTS.md + Claude 专属附注
├── COLLAB_PLAN.md                   [C]           本文件
├── ERP_PLAN.md                      [共同]        任务表勾选各改各的行
├── MERGELOG.md / HANDOFF.md         [C]           合并日志 / 代工登记
├── contracts/                       [冻结区 · C]
├── data/                            [共同]
├── composer.json / .lock            [冻结区 · C]
├── config/ bootstrap/ routes/web.php resources/views/layouts/{app,nav}.blade.php   [冻结区 · C]
├── app/Support/                     [冻结区 · C]
└── app/Modules/
    ├── Platform/      [C]    认证、角色、租户 scope、Jobs、Exceptions、Documents、Outbox(M1 只做这些);搜索、审批、审计、Webhooks 推后到 M6
    ├── MasterData/    [C]    clients(含账期 / 开票模式 / cut-off / 标准表绑定)、suppliers、carriers
    ├── Warehouse/     [C]    库位、ASN / Container(基础字段)、库存、预留、任务、波次、打包、盘点、快照、退货验收、扫码 —— **优先级 1**
    ├── Billing/       [C]    charge_codes / rules、价目表(threshold_json)、计费引擎、周仓储、发票、credit note、收款 —— M6
    ├── Transport/     [X2]   shipment、报价方案、CarrierAdapter、班次、司机页、POD、成本、对账 —— **优先级 1**
    ├── Orders/        [X1]   订单、四维状态、holds、履约批次、导入、客户报价单、退货申请 —— 先做最小集(M3)
    ├── Portal/        [X1]   客户门户入口 —— M6
    └── Reports/       [X1]   报表与定时邮件(只读跨模块数据)—— M6
```

`lang/zh/<module>.php`、`tests/Feature/<Module>/`、`resources/views/layouts/nav/<module>.blade.php` 跟随模块 owner。`DatabaseSeeder` 只含 call 列表,各加各的行。

**表所有权**沿用 ERP_PLAN §8.2 的表清单,owner 换成席位:Platform / MasterData / Billing / **Warehouse** 的表归 C;Transport 的表(shipments、transport_quotes、carrier_costs、delivery_runs、pods 等)归 X2;Orders 的表(orders、order_lines、fulfilments、holds、customer_quotes 的订单侧、return_requests)归 X1。`contracts/db-schema.md` 的 owner 列按此写。

### 1.3 任务归属(编号不变,取自 ERP_PLAN)

| 席位 | 任务 |
|---|---|
| **C** | **先:** A0 骨架与契约、A1 认证与角色、A2 主数据、A27 Job 主线、A31 Outbox 与集成监控;**B5e Vendor API Discovery**(M1 后立即做,产出 contracts/carriers.md);**WMS 全部:** B3 Outbox 接入与事件定义、B1 库存核心、B2 入库、B2b ASN 导入、B4a 预留、B2c 从 ASN 生成订单、B4 出库、B10a 快照、B10b 盘点移库隔离、B11 扫码、B12 VAS 任务、B13 退货验收、B14 多仓。**后(M6):** A24 charge codes / rules、A5 价目表、A6a 计费引擎、A6b 周仓储费、A8a 发票、A8b 手工加费 / 未开票池 / credit、A18 报价、A10 收款;A19 审批、A20 审计、A23 Webhooks、A28 异常中心、A29 文档中心、A30 全局搜索 |
| **X2** | **TMS 全部:** B5 shipment 结构与状态机、B5c TransportOptionService + CarrierAdapter(Manual 先做,Transdirect / EIZ 按 B5e 结论)、B5d 方案选择、B5b 班次编排、B6 自有 label、B7 司机页 / POD、B8 状态回写与异常、B9a 成本记录、B9b 承运商对账 |
| **X1** | **先(OMS 最小集,M3):** A3 订单模型与状态机、A4 Excel 导入(解析器与 WMS 共用)、A7 在库校验 / 预留事件 / 履约批次、A14 入库批次关联、A16 尾板判定、A11b 纯运输订单、A17 地址簿、A15 协调员 队列。**后(M6):** A13 财务锁、A7b 客户报价单、A11 改单 / 退货链路、A9-p 门户、A12 PDF 读单、A4b 订单 API、A21 报表、A22 定时报表 |

C 的清单最长,这是有意的:WMS 是优先级最高、最容易做错的模块,放在额度和能力都最强的席位上;Billing 与平台附属功能推后到 M6。X1 在 M3 之后、M6 之前有空档,可按 §5.2 的代工规则协助 X2 的 TMS(在 `block/x2-*` 分支上,登记 HANDOFF)。

---

## 2. 本地环境(全部免费,不需要服务器)

### 2.1 每台电脑装什么

| 组件 | macOS | Windows | 说明 |
|---|---|---|---|
| PHP 8.2+ / Composer | Homebrew `php` `composer`,或 **Laravel Herd**(免费版) | **Laragon**(免费,自带 PHP / MySQL / Composer)或 Herd | 三台机器 PHP 小版本可不同,`composer.json` 里 `config.platform.php` 锁到 8.2 |
| MySQL 8 | Homebrew `mysql`,或 **DBngin**(免费) | Laragon 自带 MySQL 8 | **不用 SQLite**(ERP_PLAN §8.1 铁律不变:行锁与事务是预留和 Outbox 的前提) |
| 本地网站 | `php artisan serve`(最简单)或 Herd / Laragon 的 `erp.test` | 同左 | 不需要 nginx / Apache 配置 |
| 邮件 | `MAIL_MAILER=log`,或 **Mailpit**(免费) | 同左 | 看邮件内容用 |
| 定时与队列 | `php artisan schedule:work`、`php artisan queue:listen` | 同左 | 本地开发时代替 cron;**生产仍是 cron + `--stop-when-empty`**,代码不变 |
| Git | 系统自带 / Git for Windows | | |
| Claude Code | C 的电脑 | | 需要 Node.js 18+ |
| Codex CLI | X1、X2 的电脑 | | `npm i -g @openai/codex`,用 ChatGPT 账号登录;也可用 VS Code 的 Codex 扩展。**用本地 CLI,不用网页版 Codex 云任务**(云沙箱连不到你本地的 MySQL,跑不了测试) |

每人 30 分钟内可装完。全部免费,没有任何一项要付费才能跑。

### 2.2 `.env` 与密钥

- 现在没有服务器,需要保管的只有:本地 MySQL 密码(各自的)、以后 Transdirect / EIZ 的沙箱 key。
- `.env` 不进 repo;`.env.example` 进 repo,三台机器 `cp .env.example .env` 后只改数据库账号。
- 沙箱 key 放团队密码库(Bitwarden 免费版即可),不放聊天记录。

### 2.3 没有服务器时怎么演示 / 联调

- 演示:在 C 的电脑上 `php artisan serve --host=0.0.0.0`,同一 Wi-Fi 内手机可直接开扫码页;要给外部看用 **Cloudflare Tunnel**(免费)临时暴露。
- 三方联调:不需要共享数据库。每台机器 `migrate:fresh --seed` 得到同一份种子数据(真实价目表 + 脱敏清单),按 §7 验收脚本各自跑;跨模块行为靠契约 + Feature 测试保证,不靠"连到同一台服务器"。
- 买服务器的时机:M5 之后、给客户看之前。到时由 C 写 `deploy/DEPLOY.md`,代码零改动(这是保留"无常驻进程"约束的原因)。

### 2.4 CI(免费)

GitHub Actions 对私有仓库每月有免费额度,足够跑 PR 检查。`.github/workflows/ci.yml`:`services: mysql:8` → `composer install` → `migrate:fresh --seed` → `pint --test` → `phpunit`。**这是三方协作最重要的护栏**:三个不同模型写的代码,靠 CI 与契约而不是靠信任对齐。(CI 里用容器不违反"生产不用 Docker"。)

---

## 3. 指令文件:AGENTS.md 为主本

Codex 读 `AGENTS.md`,Claude Code 读 `CLAUDE.md`。两份内容必须一致,所以:

- **`AGENTS.md`** = 完整规则(内容即现有 `CLAUDE.md`,把"Account A / B"改成"Seat C / X1 / X2",目录归属按 §1.2)。
- **`CLAUDE.md`** = 第一行 `@AGENTS.md`(Claude Code 支持导入),后面只放 Claude 专属内容:Integrator 子代理、检查点合并流程。
- **席位身份不进 repo**:
  - C:`~/.claude/CLAUDE.md` 或仓库根 `CLAUDE.local.md`(git-ignored)写 `SEAT=C`。
  - X1 / X2:`~/.codex/AGENTS.md`(Codex 会把全局 AGENTS.md 与仓库 AGENTS.md 合并读取)写 `SEAT=X1`,`You may only edit app/Modules/{Orders,Portal,Reports}, …`。
  - 身份文件缺失时,规则要求先问再动手。
- Codex 会话建议在 `~/.codex/config.toml` 里把审批模式设为需要确认写文件以外的动作(如执行 `git push`),避免误操作;沙箱保持 workspace-write。

---

## 4. 分支与检查点(取代 ERP_PLAN §8.5)

**原则不变:不做每日合并;版块分支连续开发;大版块测试全绿才在检查点合入 `main`;检查点是顺序不是日期。** 变化是三条流水线、固定的合并人,以及 **WMS / TMS 先行** 的顺序。

分支命名:`block/<seat><n>-<slug>`,如 `block/c1-platform`、`block/c2-wms-core`、`block/x2-tms`、`block/x1-oms-min`。

| 检查点 | 谁 | 合入内容 | 解锁 |
|---|---|---|---|
| **M0** | C | Block 0:骨架 + 模块目录 + `contracts/` 八个文件(enums / events / db-schema / services 先写完整,charge-codes 可先只列 code 名)+ CI + `AGENTS.md` / `CLAUDE.md` + Fake Service 骨架 + 冻结区定稿 | 一切。M0 期间 X1 / X2 只做:装环境、跑通 `php artisan test`、通读自己模块章节与 contracts |
| **M1** | C | `block/c1-platform`:A1 认证与角色、A2 主数据、A27 Job 主线、A31 Outbox(**最小集**,平台附属功能推后) | X1 需要用户 / 客户 / Job;X2 需要 Outbox 发布器与 Job;C 自己开 WMS |
| **B5e** | C | Transdirect / EIZ Go / No-Go 结论 → `contracts/carriers.md`(M1 之后立刻做,不是合并点) | X2 的 B5c 适配器范围;结论前 X2 只做 Manual 适配器 |
| — | 三方并行 | M1 之后同时开工:C → `block/c2-wms-core`;X2 → `block/x2-tms`;X1 → `block/x1-oms-min`。互不依赖:WMS 预留消费 `order.confirmed` 用测试事件;TMS 报价用 Fake 包裹与纯运输订单 | |
| **M2** | C | `block/c2-wms-core`:B3、B1、B2、B2b、B4a(库存、入库、ASN 导入、预留) | X1 的在库校验换真 `StockService`;X2 可读真实 packages 结构 |
| **M3** | X1 | `block/x1-oms-min`:A3、A4、A7、A14、A16、A11b、A17、A15 | C 的 B2c 用真 `OrderService::createFromAsn`;WMS 出库有真订单;TMS 有纯运输订单可派 |
| **M4** | C | `block/c3-wms-outbound`:B2c、B4、B10a、B10b、B11、B12、B13、B14(出库、快照、盘点隔离、扫码、VAS、退货验收、多仓) | TMS 有真实 `outbound.packed` 与 packages 可报价 |
| **M5** | X2 | `block/x2-tms`:B5、B5c、B5d、B5b、B6、B7、B8、B9a、B9b | **WMS + TMS 全链路可演示**(见 §0);费用事件已发出 |
| **M6** | C → X1 依次 | C `block/c4-billing`(A24、A5、A6a、A6b、A8a、A8b、A18、A10)+ `block/c5-platform-rest`(A19、A20、A23、A28–A30);X1 `block/x1-portal-reports`(A13、A7b、A11、A9-p、A12、A4b、A21、A22) | 计费上线,集成收口;之后只留 `fix/*` 短分支跑 ERP_PLAN §7 验收脚本 |

规则:
- 版块内直接提交到版块分支;**每个会话结束必须 push**。
- 每个检查点合并后,另外两席**立刻** `git rebase main`(C 在合并日志里 @ 两人)。
- 检查点合并 = X 开 PR → CI 绿 → C 运行 Integrator 流程(§6)→ 合入 → 打 tag。**X1 / X2 不合并 `main`,不 push `main`。** C 自己的版块也走 PR + Integrator,不直接 push main。
- 迁移已合入 `main` 后永远不改;要改结构新增迁移。
- 对方版块未合并前,依赖方按 `contracts/services.md` 写 Fake 先行(M0 已放骨架)。
- **计费事件从 M2 起就按 `contracts/events.md` 逐字发出**(`asn.putaway_completed`、`outbound.packed`、`task.completed`、`shipment.quote_confirmed`、`snapshot.weekly` 等,payload 字段齐全),M6 的 Billing 只消费、不回头改 WMS / TMS。
- X2 的 TMS 分支可以早于 M4 就绪;C 按顺序在 M4 之后合它。X2 等合并期间做 B8 / B9 或按 HANDOFF 协助。

## 5. 会话仪式与 Codex 额度管理

### 5.1 每次会话(三席相同,取代 ERP_PLAN §8.6)

```bash
# 开工
git fetch --all --prune
git checkout block/<自己的当前版块> && git pull --rebase
composer install
cp -n .env.example .env && php artisan key:generate      # 只改本地 DB 账号
php artisan migrate:fresh --seed
php artisan test                                          # 绿了再开工
# 读 AGENTS.md → ERP_PLAN 对应任务块与 contracts/ → 开写

# 收工
php artisan test && ./vendor/bin/pint
git add -A && git commit -m "<seat>/<block>: <task id> <what>"
git push origin block/<自己的当前版块>
# 在 ERP_PLAN.md 任务表打勾
```

### 5.2 Codex 席位的额外规则(额度是有限资源)

Plus 档的 Codex 有按时间窗滚动的用量上限(以账号里实际显示为准),用完要等窗口重置。所以:

1. **一次会话只做一个任务编号**(如 B2b),做完就 commit + push。不要在一个会话里连做三个任务,中途额度耗尽会留下半成品。
2. **少探索,多照单**:开工时只读 `AGENTS.md`、ERP_PLAN 里自己任务的那一段、相关 contracts 文件;不要让 Codex 通读整个 ERP_PLAN(16 万字符,纯烧额度)。
3. **半成品也要 push**:额度耗尽前 `git commit -m "WIP: B4 拣货任务 — 剩打包页"` 并 push,写清剩什么。
4. **溢出规则(代工)**:某席额度用尽而检查点在等它时,C 可以接手该席**同一版块分支**上的下一个任务。前提:在 `HANDOFF.md` 登记(日期、分支、从哪个任务接、预计交还时间),且**同一时间一个分支只有一个席位在写**。归属绑定分支而不是人:C 在 `block/x2-*` 上工作时遵守 X2 的目录规则。
5. **不用 Codex 云任务**跑需要数据库的工作;云端只适合让它读代码写评审意见。

### 5.3 C 席位的额外职责

- 每周一次"漂移检查":只读方式拉取两条 X 分支,对照 contracts 看枚举、事件 payload、Service 签名有没有走样;发现即在 PR 评论或 `MERGELOG.md` 里写明,不动对方代码。
- 契约变更的唯一入口:X1 / X2 需要改 contracts 时,在自己分支的 `contracts/CHANGE_REQUESTS.md` 追加一条,C 在下一个检查点决定并统一改。

---

## 6. 检查点合并流程(取代 ERP_PLAN §8.8,仍是 Claude Code 子代理,只在 C 上运行)

`.claude/agents/integrator.md` 内容沿用 ERP_PLAN §8.8,改三处:

1. 所有权检查按 §1.2 的三方目录表;越界文件列出并停止。
2. 合并前先 `git fetch` 对应的 PR 分支并确认 CI 已绿;CI 未绿不合并。
3. 合并后在 `MERGELOG.md` 追加:合入版块、新增表、新增事件、契约变更、测试数、**@ 另两席 rebase**。

Codex 席位没有对应的子代理机制,也不需要:它们只负责把 PR 开到 CI 绿。

---

## 7. 可行性评估

| 维度 | 结论 | 说明 |
|---|---|---|
| 技术栈本地免费运行 | **完全可行** | PHP、Laravel、MySQL Community、Composer、Mailpit、Laragon / Herd 免费版全部免费;没有任何功能依赖付费服务。没有服务器只影响"给别人看",不影响开发与测试 |
| 三个不同模型协作 | **可行,靠三道护栏** | ① 目录级隔离(几乎不碰同一文件);② `contracts/` 逐字 + CI 测试(行为对齐不靠模型一致);③ C 固定做合并与评审(单一裁判)。三道都在本文里 |
| 并行度 | **优于原方案** | 原来 Billing 要等 A 做完 OMS;现在 C 做 Billing、X1 做 OMS、X2 做 WMS 同时进行,M1 之后三条线并行 |
| Codex Plus 额度 | **最大风险,可控** | 见 §5.2:单任务会话、WIP push、代工规则。若某席频繁触顶,备选是把该席升到更高档,或把其模块的一半移给 C |
| 代码质量差异 | 可控 | pint 统一格式;契约 + Feature 测试统一行为;C 的漂移检查抓早期偏差。Codex 在"照字段级设计实现 CRUD + 测试"这类任务上表现稳定,ERP_PLAN 的 WMS / OMS 章节正是这种粒度 |
| 三方合并冲突 | 低 | 会碰面的只有 `DatabaseSeeder` call 列表、nav include、lang 文件 —— 都是"各加各的行",Integrator 规则是两边都保留 |
| 后续买服务器 | 零改造 | 保留了"无常驻进程 / cron / MySQL / SMTP"约束;`DEPLOY.md` 到时补 |
| 成本 | 低 | Claude Max + 2 × Codex Plus + GitHub 免费版 + 全部免费本地软件 |

**不建议的两件事:** ① 为了"本地免费"改用 SQLite —— 预留加锁与 Outbox 同事务在 SQLite 上行为不同,上线换 MySQL 会出隐蔽 bug;② 让三席共用一个远程数据库 —— 迁移互相打架,而且本来就不需要。

---

## 8. 下一步(按顺序)

1. C:建 GitHub 私有仓库,加 X1 / X2 为协作者;开 `block/c0-bootstrap` 做 M0(骨架、contracts 八个文件、CI、`AGENTS.md` / `CLAUDE.md`、Fake 骨架)。
2. X1 / X2:装本地环境(§2.1),各自 `~/.codex/AGENTS.md` 写席位身份;clone 后跑通 `php artisan test`;通读自己模块章节 + contracts,把疑问写进 `contracts/CHANGE_REQUESTS.md`。
3. C:M0 合入 → 立刻开 `block/c1-platform` 做 M1(最小集);M1 合入后先做 B5e 调研,再开 `block/c2-wms-core`。
4. M1 合入后三线并行(§4):C 做 WMS 核心,X2 做 TMS,X1 做 OMS 最小集。
5. ERP_PLAN.md 同步:把 §8.2 / §8.5–8.8 改为"见 COLLAB_PLAN.md",§8.1 部署一句改为"服务器暂缓,本地开发;约束保留"。

```
M0  C:骨架 + 契约          X1 / X2:装环境、通读、写 CHANGE_REQUESTS
M1  C:平台最小集           ── 合入后三线并行 ──
B5e C:承运商 Go / No-Go     X2 据此定 B5c 范围(结论前只做 Manual)
     C:block/c2-wms-core    X2:block/x2-tms            X1:block/x1-oms-min
M2  C WMS 核心 ─► 合入       X1 换真 StockService
M3  X1 OMS 最小集 ─► 合入    C 开 block/c3-wms-outbound(B2c 用真 OrderService)
M4  C WMS 出库 ─► 合入       X2 用真 outbound.packed 联调报价
M5  X2 TMS ─► 合入          ★ WMS + TMS 全链路可演示
M6  C billing ─► C platform-rest ─► X1 portal-reports 依次合入
之后 只留 fix/* 分支,三方按 ERP_PLAN §7 端到端脚本验收
```
