# Logistics ERP — 全模块开发计划 (v4.7)

> 依据:PRD_Draft_updated.docx(2026-08-30)· 真实数据:《需派送货物清单》《客户价目表 27/02/2026》
> **本版重点:每项能力先研究同行业最强产品的优势(机制层面),再决定我们取什么、舍什么、为什么。**
> v4.7 变更(2026-09-07):按 Edward 价目表基础功能修订 WMS / Billing —— 托盘阈值改 1200 × 1200(标准 / 超高 / 超宽 / ≥ 800 kg POA)、账期按客户配置(prepaid / eom / net_N)且不卡预订发运、invoice_mode 改为 per_job / monthly、两个 Label 项纳入自动计费、Container 只留基础字段、价目表所有数字可配不写死、新增托盘来源 / pickface 库位 / 序列号记录 / 班内班外人工时 / 废弃物 CBM。
> v4.6 变更:仅调整章节顺序为 总览 → 通用能力 → Platform → OMS → WMS → TMS → Billing,章节编号与 § 交叉引用同步重编,内容未改。
> 排期与工期不在本文范围。本文为**唯一**计划文件:业务与数据模型(§1–§7)+ 技术栈、仓库、协作与契约(§8),供 Claude Code 与团队直接使用;原 `DEVELOPMENT_PLAN.md`、`OMS_PLAN.md` 已并入并作废。

---

# 0. 总览

## 0.1 五个模块与归属

| 模块 | 账号 | PRD 需求 | 位置 |
|---|---|---|---|
| Platform 平台基础 | A | PLT-1 ~ PLT-8 | §2 |
| OMS 订单 | A | OMS-1 ~ OMS-14 | §3 |
| WMS 仓库 | B | WMS-1 ~ WMS-10 | §4 |
| TMS 运输 | B | TMS-1 ~ TMS-6 | §5 |
| Billing 计费 | A | FIN-1 ~ FIN-8 | §6 |
| 技术栈、仓库、协作、契约 | A+B | — | §8 |

## 0.2 跨模块铁律(不可协商)

1. **Job 是业务主线**:Job = 一次独立的客户业务委托。orders、asns、shipments、charges、carrier_costs、documents、exceptions、quotes、invoice_lines 全部挂 `job_id`。**ASN 是入库主单**(收货、差异、上架、库存都由它管理);**Container 是 ASN 下的可选物理对象**,只有整柜入库才创建。整柜业务默认一柜一 Job,但系统必须允许一个 Job 无 Container 或关联多个 Container。Container 只记基础字段(柜号、柜型、拆柜方式、毛重、行数),不做柜级生命周期与单证。
2. **表所有权**:每张表只有一个 owner 账号写 migration 和 model。
3. **跨模块禁止写入他人业务表**:状态变化与副作用走 `domain_events`(B 写、A 消费;B 永远不写 charges,A 永远不写 stock_ledger);同步查询走公开 Application Service(`StockService`、`RateService`、`JobService`)或只读模型,不依赖异步事件。
4. **事件必须可靠(事务 Outbox)**:`outbox_events` 由 Platform 拥有;业务写入与事件写入必须在**同一个数据库事务**里提交,要么都成功要么都失败;每条事件有 event_id、event_version、correlation_id;消费方记 consumed_events(inbox 幂等);失败自动重试,重试耗尽进失败事件队列并告警。否则会出现"仓库做完了、费用永远没生成"。
5. **枚举逐字等于 `contracts/enums.md`**:状态、UOM、charge code、包裹类型、角色名。
6. **对方版块未合并前用 Fake 实现**(`FakeStockService`、`FakeRateService`、`FakeJobService`)。
7. **金额一律整数分、AUD**;费率不含税,税按每个 charge code 的 `tax_treatment`(gst_10 / gst_free / out_of_scope)在发票按行汇总;仓储费按托盘·周。
8. **价目表里的所有数字都是可配置参数**:单价、分档边界(22 / 45 kg)、托盘尺寸与重量阈值(1200 × 1200 × 1400 / 1800 / 2400 mm、800 kg)、拆柜行数上限(20)、集装箱吨位门槛(22.5 t)、最低收费、尾板阈值、加急 cut-off —— 全部存在客户价目表 / 客户主数据里,代码不写死,每个客户可不同;Edward 价目表作为标准表的默认值。
9. **账期按客户配置**(`clients.payment_terms` = prepaid | eom | net_N):发票只算到期与逾期,**系统不因未收款阻止预订或发运**;财务锁只由 Finance / Coordinator 人工置。

## 0.3 对象层级(全系统统一)

**Job 与 Order 不是同一层级的东西:** Job = **商业结算与业务归集单元**(一次客户委托的钱和货都归到这里);Order = **一次配送或操作指令**(把这些货送到哪、怎么送)。一个 Job 下可以有零到多张 ASN、零到多张订单;纯运输 Job 没有 ASN,退货 Job 只有退货订单。

```
Customer
 └─ Job                          商业结算 / 业务归集单元(一次独立的客户业务委托)
     ├─ ASNs                     入库主单(0..n):收货、差异、上架
     │    ├─ Containers          可选物理对象(0..n),只有整柜入库才有
     │    ├─ ASN Lines           货物行 = 库存身份
     │    │    └─ Stock Units    库存单元 = 客户 + 货物行 + 包装单元 + 库位
     │    └─ Warehouse Tasks     收货 / 上架 / 拆柜等作业任务
     ├─ Orders                   配送或操作指令(0..n):出库派送 / 纯运输 / 退货
     │    ├─ Declared Packages   申报包裹(初步报价依据;纯运输的唯一依据)
     │    ├─ Fulfilments         履约批次(可分次、跨仓)
     │    │    └─ Warehouse Tasks  拣货 / 打包任务 → Packages(实测)
     │    └─ Shipments           发运(outbound | return),挂报价方案、预订、POD、成本
     ├─ Charges                  费用(由各作业事件经 charge_rules 产生)
     ├─ Carrier Costs            成本
     └─ Documents / Exceptions   单据与异常(Platform 共享表,引用 Job)

Invoice                          可覆盖一个或多个 Job 的费用
```

## 0.4 取舍的三条判据

研究对标产品时,每个能力用同一把尺子衡量:

- **判据一:它解决的问题我们有没有。** Logiwa 的拣货路径算法解决"每天几万单、走动成本高";我们一天几十到几百单,没有这个问题 → 不采用。
- **判据二:它的做法依赖什么前提。** Extensiv 的电商连接器依赖"客户是网店";我们的客户是货代与企业 → 不采用。
- **判据三:简化后还成不成立。** Microlistics 的任务化执行简化成"按库位排序的清单"仍然成立;MachShip 的多承运商简化成"留一个 Adapter 接口"也成立 → 简化采用。

---

# 1. 通用能力研究(五个模块共用)

### 1.1 一个数据库 —— CargoWise

**优势:** CargoWise 的 "one platform, one database" 不是营销词,是架构选择:全球所有分公司、所有模块(货代、报关、仓储、运输、会计)写同一个数据库。直接结果是**减少数据复制与跨系统同步** —— 一票货的运费、仓储费、报关费挂在同一条记录链上。但单库不保证业务数字自动一致:WMS 发运 100 箱、TMS 包裹 98 箱、承运商接货 97 箱、发票按 100 箱收费仍可能发生,所以仍需共享 Reference、约束、事件处理与对账来保证一致。它靠"一个数据库 + Job 主线"在货代行业成为事实标准。

**采用:** 模块化单体 + 单库;模块之间用事件传递状态、用 Application Service 同步查询;共享 Reference(job_id)贯穿所有单据。
**不采用:** 全球单库的复杂度(多时区、多币种、多法域)—— 我们只有澳洲一地、一个币种。

### 1.6 Job 主线 —— CargoWise

**优势:** CargoWise 的每一票业务都是一个 Job:订单、仓储、运输、单据、费用、成本全部挂在 Job 上,毛利是 Job 的属性而不是月底从多张表拼出来的报表。没有 Job 的系统里,"这个柜赚了多少"要从订单、ASN、shipment、charges、costs 五张表临时关联,而且无柜业务(散货、纯运输)找不到统一的挂靠点。

**采用:** 新增 `jobs` 表(job_no、client_id、job_type、operational_status、financial_status、estimated / actual revenue 与 cost),所有业务单据加 `job_id`;Job = 一次独立的客户业务委托,整柜业务默认一柜一 Job,但允许无 Container 或多 Container;Job 工作台把各模块数据串起来(§2)。
**Job 状态定义(运营与财务分离,各自从子单据推导):**

```
jobs
├─ job_no, client_id, job_type(container | loose | transport_only | return)
├─ operational_status   open → receiving → in_stock → dispatching → completed | cancelled
│                       推导:有 ASN 未关闭 = receiving;ASN 全关闭且有库存 = in_stock;
│                       有 fulfilment 在途 = dispatching;全部 ASN 关闭且全部订单 delivered / cancelled = completed
├─ revenue_status       unbilled → partially_invoiced → invoiced → paid      推导自 charges / invoices / payments
├─ cost_status          estimated → partially_confirmed → confirmed         推导自 carrier_costs 的 expected / actual
├─ estimated_revenue, actual_revenue, estimated_cost, actual_cost(缓存,由服务重算)
└─ 毛利在 cost_status = confirmed 前显示为"预估",之后为"实际"
```

成本确认与收入确认是两条线:客户付款不等于成本已确认(承运商账单可能两周后才来),两者各自推进,Job 页分别显示。

**不采用:** CargoWise Job 的货代字段体系(多段国际运输、代理分润、报关)。

### 1.2 多货主隔离 —— CartonCloud / Extensiv

**优势:** 这是 3PL 软件与普通仓库软件的分水岭。CartonCloud 出身于自家 3PL,从第一行代码起每条库存就带客户标识;隔离做在**数据层**而非报表层,所以能自然长出两样东西:按客户计费,和客户只见自己数据的门户。反过来,把电商自营仓 WMS 改造给 3PL 用,常常在这一步崩掉 —— 隔离是后加的,总有漏的地方。

**采用:** 全局 client scope 中间件,所有查询、导出、API 自动受限;库存键包含客户。
**不采用:** 无。这条必须原样采用。

### 1.3 作业即计费 —— CartonCloud

**优势:** 3PL 老板最痛的不是仓库效率,是**月底算不清账**:哪些货存了多久、哪些单收了操作费、运费按哪档算,全靠 Excel 拼。CartonCloud 让仓库和配送的每个动作自动落一条费用,月底一键出账并直连会计软件。一旦客户把费率卡配进系统,换系统等于重配所有费率 —— 这就是它的护城河。机制上的关键是:**"什么动作产生什么费用"是配置而不是代码**,所以同一套代码能服务费率结构完全不同的客户。

**采用:** 作业写 `domain_events` → 计费引擎消费生成 charges;每条费用挂源单据可溯源。
**不采用:** 会计软件对接(FIN-4 已从 PRD 删除),只留导出接口。

### 1.4 移动优先 —— CartonCloud / TransVirtual

**优势:** 中小物流公司换系统的直接理由往往不是功能,是**一线工人愿不愿意用**。CartonCloud 让仓库工人在手机上收货拣货,TransVirtual 让司机在手机上签收拍照,都不必回办公室补录。纸单消失的同时,数据也实时了。

**采用:** 手机浏览器网页(扫码页、司机页),不做原生 App。
**不采用:** 离线模式与原生 App 的推送/相机优化 —— 澳洲城区网络够用,离线是二期问题。

### 1.5 客户自助 —— CargoWise Neo / Magaya LiveTrack

**优势:** 两家有层次差别。Magaya LiveTrack 是**查询型**门户:客户自己看货到哪了、下载单据,直接砍掉"我那批货到哪了"这类电话。CargoWise Neo 更进一步做成**可操作型**工作台:客户能下单、传单据、看费用,日常事务自己完成。对 3PL 还有个副作用 —— 客户的客户也进了系统,软件顺着一家客户渗透到几十家货主。

**采用:** 门户与内部页同一套代码、两种权限视图;客户能下单、查单、查库存、下账单。
**不采用:** 报价议价、单据协作等深度功能 —— 先做到"日常不用打电话"。

---

# 2. Platform 平台基础(账号 A)

## 2.1 能力研究与取舍

### 2.1.1 多租户与数据隔离 —— CartonCloud / Extensiv

**优势:** 见 §1.2。平台侧的关键是隔离必须做在**数据访问层**:任何新增页面、导出、API 默认就是受限的,开发者不需要每次记得加过滤条件。做在页面层的隔离迟早会漏。

**取舍:** **原样采用**。全局 client scope 中间件 + 八个角色(管理员、客服、调度、仓库主管、仓库操作、运输操作、财务、客户)。

### 2.1.2 可操作的客户门户 —— CargoWise Neo

**优势:** 见 §1.5。补一个我们特别在意的点:Neo 与主系统**共用同一套数据与权限**,不是另做一个前端。这决定了门户的维护成本 —— 两套代码意味着每加一个字段要改两遍。

**取舍:** **采用"同一套代码、两种权限视图"**;客户视图隐藏成本与毛利字段。**舍弃** 门户内的报价议价与单据协作。

### 2.1.3 审批 —— Extensiv

**优势:** 敏感动作(改价、开 credit、大额库存调整)需要第二人批准且留痕。这既是内控,也是出问题时能查清责任的前提。

**取舍:** **采用**,做成统一的审批中心,同时服务:价目表变更、credit、大额调整、**OMS-11 财务放行**、拣货后改单。

### 2.1.4 审计留痕 —— CargoWise

**优势:** CargoWise 服务的货代要面对海关与审计,所以留痕是产品的底层能力而非附加功能:谁在什么时候改了什么,全部可查。

**取舍:** **采用**。activitylog + 各模块时间线。这也是 PRD 第 8 节提到的"新投资方进来前要理清"那类问题的前提。

### 2.1.5 对外集成 —— Extensiv Integration Manager / Descartes

**优势:** Extensiv 把集成单独做成一个产品(原 CartRover),因为 3PL 的每个客户都想用自己的系统;Descartes 更极端,整个公司的资产就是那张连接承运商与海关的网络。共同点是:**集成能力本身就是产品价值**,不是附属功能。

**取舍:** **采用接口设计,不采用集成规模**。一期把 webhooks 与开放 API 的接口留好;不做连接器市场。理由(判据一):现在没有客户要求接入,但接口没留好,以后加会推翻模型。

### 2.1.6 Job 工作台、异常中心与文档中心 —— CargoWise / Extensiv

**优势:** CargoWise 的 Job 页把订单、仓储、运输、单据、费用、成本放在一个页面,任何角色都从 Job 进入;异常(仓库差异、配送失败、计费缺费率、集成失败)有统一入口,而不是散在各模块;单据(POD、Docket、照片、发票)有统一的类型、关联对象与可见性。Extensiv 的集成产品则把"哪个集成失败了、能不能重试"做成看板。

**取舍:** **采用**:Job 工作台(Platform 提供 `JobService` 与页面骨架,各模块贡献自己的面板)、Exception Centre(统一 `exceptions` 表,取代仅 OMS 的 Coordinator 队列)、统一 `documents` 表(类型、关联对象、client 可见性)、Integration Monitoring(TD/EIZ 调用与事件失败的重试与告警)、Global Search(Job、订单、唛头、柜号、tracking、发票)。安全、备份、性能、可观测性作为工程基线(§2.6)。**不采用**:导入历史泛化(各导入自记历史即可)、门户角色矩阵(客户角色暂只一种,细分待后续;成本与毛利对客户不可见的规则在 PLT-1 保持)。

### 2.1.7 报表 —— MachShip(承运商绩效)/ Extensiv(货主视角)

**优势:** 两家展示了报表的两个方向:MachShip 给的是**运营绩效**(准时率、每公斤成本、承运商对比),Extensiv 给的是**每个货主自己的 KPI**(单量、库存周转、费用)。对 3PL 来说两者都需要 —— 一个给老板,一个给客户。

**取舍:** **采用"内外两套视角"**;指标先做单量、准时率、库存量、费用与毛利。**舍弃** 自定义报表构建器。

## 2.2 屏幕清单

| 屏幕 | 用途 | 对应 PRD 需求 |
|---|---|---|
| 登录 / 用户管理 | 建用户、指派角色、限定仓库与客户范围 | **PLT-1 用户与权限** — 八个角色;客户用户只能看到本公司数据 |
| 客户主数据 | 名称、联系人、状态、账单邮箱、ABN、头程/尾程类型 | **PLT-2 共享主数据** — 一套客户、供应商、仓库、库位、承运商登记供全系统使用 |
| 供应商 / 承运商 | 登记与维护 | **PLT-2** |
| (仓库 / 库位) | 表与页面都在 Warehouse 模块(B);Platform 只在菜单里链接过去 | **PLT-2** |
| Job 工作台 | 一个 Job 的订单、入库、库存、发运、单据、费用、成本、毛利在一页 | **PLT-3 / PLT-4** 的串联视图;CargoWise Job 页 |
| 异常中心 | OMS / WMS / TMS / Billing 的异常统一列表:收货差异、Pick Short、配送失败、Missing Rate、Billing Hold、集成失败 | **OMS-2 / TMS-3 / FIN-2** 的异常处理入口 — 取代仅 OMS 的 Coordinator 队列 |
| 文档中心 | 所有单据按类型与关联对象归档(POD、Docket、照片、waybill、发票),含客户可见性 | **TMS-2 / TMS-4 / FIN-3** — 单据一处管理 |
| 全局搜索 | 按 Job、订单号、唛头、柜号、tracking、发票号直达 | 全模块 |
| 集成监控 | TD/EIZ 调用日志、失败事件队列、重试 | **PLT-6** 的运维面;铁律第 4 条的落地 |
| 客户门户首页 | 客户入口:下单、查单、查库存、下账单;客户角色暂只一种 | **PLT-3 客户门户** |
| 报表(老板) | 单量、准时率、库存量、费用与毛利 | **PLT-4 报表** — 管理层一套视角 |
| 报表(客户) | 该客户的单量、库存、费用 | **PLT-4** — "one view for management, one for each client" |
| 定时报表 | 按计划给客户发邮件报表 | **PLT-5 定时客户报表** |
| 审批中心 | 待审批:价目表变更、credit、大额调整、财务放行 | **PLT-7 审批** — 敏感动作需第二人批准后生效 |
| 审计日志 | 谁、何时、改了什么 | **PLT-8 审计留痕** |

## 2.3 任务概览

| # | 任务 | 依赖 | 对标与取舍 |
|---|---|---|---|
| A0 | ✅ M0 · 仓库初始化:骨架、模块目录、contracts、CI、Seeder 框架 | — | CargoWise 一个数据库(模块化单体落地) |
| A1 | ✅ M1 · 认证 + 8 角色 + 客户数据隔离 + 用户管理 | A0 | CartonCloud 隔离做在数据层(原样) |
| A2 | ✅ M1 · 主数据:客户(ABN / 类型 / invoice_mode(per_job / monthly)/ payment_terms(prepaid / eom / net_N)/ default_markup_percent / dispatch_cutoff_time / standard_rate_card_id)、供应商、承运商主数据(仓库 / 库位归 WMS,在 Warehouse 模块维护) | A1 | CargoWise 主数据一处维护 |
| A27 | ✅ M1 · Job 主线:jobs 表 + JobService + 各模块 job_id 外键 + Job 工作台骨架 | A2 | CargoWise Job(原样) |
| A28 | ✅ c5 · 异常中心:统一 exceptions 表与列表;各模块异常接入 | A27 | CargoWise 异常统一入口 |
| A29 | ✅ c5 · 文档中心:统一 documents 表、上传组件、客户可见性 | A27 | CargoWise 单据管理 |
| A30 | ✅ c5 · 全局搜索(Job / 订单 / 唛头 / 柜号 / tracking / 发票) | A27 | — |
| A31 | ✅ M1 · 集成监控与事件可靠性:event_id / inbox / 幂等 / 重试 / 失败队列 / 告警(与 B3 共建) | A0, B3 | Extensiv Integration Manager |
| A19 | ✅ c5 · 审批中心(统一入口) | A1 | Extensiv 审批 |
| A20 | ✅ c5 · 审计日志(activitylog + 查询页) | A1 | CargoWise 留痕 |
| A21 | 报表:老板视角 + 客户视角 | 多数 | MachShip 绩效 · Extensiv 货主视角(舍报表构建器) |
| A22 | 定时客户报表(邮件) | A21 | Extensiv 定时报表 |
| A23 | ✅ c5 · Webhooks 事件推送 | A0 | Extensiv 集成接口(留接口不做市场) |

> 门户(PLT-3)的订单部分在 §3 的 A9-p;库存与账单部分复用 WMS/Billing 页面的客户视图。

## 2.4 每个任务满足哪条需求

**A0 · 仓库初始化**

`对标:CargoWise 一个数据库`

- 架构任务。建立 §0.2 铁律赖以生效的结构:模块目录、contracts、CI、冻结区。

**A1 · 认证 + 角色 + 客户数据隔离**

`依赖 A0 · 对标:CartonCloud 数据层隔离`

- **PLT-1 用户与权限** — 八个角色;客户用户只能看到本公司数据。隔离做在数据层(全局 scope),不靠页面判断。

**A2 · 主数据**

`依赖 A1 · 对标:CargoWise 主数据一处维护`

- **PLT-2 共享主数据** — 客户(含 ABN、账单邮箱、头程/尾程类型)、供应商、承运商主数据;仓库与库位由 Warehouse 模块维护(表归 B);无产品目录,货物信息随订单行。

**A27 · Job 主线**

`依赖 A2 · 对标:CargoWise Job`

- 铁律第 1 条的落地:`jobs` 表、`JobService`(建 Job、查 Job、汇总收入 / 成本)、各模块 job_id 外键、Job 工作台骨架;Job = 一次独立的客户业务委托,整柜默认一柜一 Job,允许无 Container 或多 Container;OMS-12 入库批次关联由 Job 实现。

**A28 · 异常中心**

`依赖 A27 · 对标:CargoWise 异常统一入口`

- 统一 `exceptions` 表(类型、来源模块、关联 Job 与单据、状态、处理人);收货差异、Pick Short、配送失败、Manual Transport、Missing Rate、Billing Hold、集成失败全部接入;取代仅 OMS 的 Coordinator 队列(A15 变为异常中心的一个筛选视图)。

**A29 · 文档中心**

`依赖 A27 · 对标:CargoWise 单据管理`

- 统一 `documents` 表:类型(POD、docket、照片、waybill、发票、装箱单)、关联对象、client 可见性;各模块的上传统一走此组件;门户按可见性展示。

**A30 · 全局搜索**

`依赖 A27`

- 一个搜索框直达 Job、订单、唛头、柜号、tracking number、发票号。

**A31 · 集成监控与事件可靠性**

`依赖 A0、B3 · 对标:Extensiv Integration Manager`

- 铁律第 4 条的落地:domain_events 加 event_id / event_version / correlation_id,消费方 consumed_events 幂等,失败自动重试,重试耗尽进失败队列并告警;TD/EIZ 调用日志与重试;看板可见。

**A19 · 审批中心**

`依赖 A1 · 对标:Extensiv 审批`

- **PLT-7 审批** — 价目表变更、credit、大额库存调整需第二人批准;同一入口服务 OMS-11 财务放行与拣货后改单。

**A20 · 审计日志**

`依赖 A1 · 对标:CargoWise 留痕`

- **PLT-8 审计留痕** — 每次重要变更记录谁、何时、改了什么,可查阅。

**A21 · 报表**

`对标:MachShip 承运商绩效、Extensiv 货主视角`

- **PLT-4 报表** — 单量、准时送达率、库存水平、费用汇总;管理层一套、每个客户一套。

**A22 · 定时客户报表**

`依赖 A21`

- **PLT-5 定时客户报表** — 客户按计划自动收到邮件报表(如每周库存、每日活动)。

**A23 · Webhooks**

`依赖 A0 · 对标:Extensiv Integration Manager`

- **PLT-6 事件推送** — 订单发运、送达、库存变化时通知客户自有系统。当前无客户要求,但接口要留好。

## 2.5 验收标准

1. 客户账号登录后,任何页面、导出、API 都拿不到别家数据;
2. 仓库操作员打开财务页面被拒绝;
3. 价目表变更提交后进入审批中心,批准后才生效;
4. 审计日志能查到"谁在什么时候改了哪张价目表的哪一项";
5. 老板报表显示单量、准时率、毛利;客户报表只含该客户数据;
6. 从任一 Job 页能看到该 Job 的订单、入库、库存、发运、单据、费用、成本与毛利;
7. 收货差异、配送失败、Missing Rate 三类异常出现在同一个异常中心列表;
8. 人为让 Billing 的事件消费失败一次,重试后费用仍正确生成,失败记录可在集成监控中看到。

## 2.6 工程基线清单(不作为业务任务,开工前配好)

1. 安全:密码策略、会话过期、文件访问鉴权、MFA 预留;
2. 备份与恢复:数据库每日备份 + 文件存储备份,恢复演练一次;
3. 性能:列表分页、导出走后台队列、批量任务异步;
4. 可观测性:错误日志、队列监控、失败事件队列、慢查询日志;
5. 双语文案包:状态、菜单、邮件、错误信息全部走 lang 文件(中文优先,英文可切换)。

---

# 3. OMS 订单模块(账号 A)

## 3.1 这个模块要解决什么

OMS 是整个系统的入口:所有货物要做的事都从一张订单开始,后面仓库、配送、计费都是它的下游。PRD 给了 10 条要求,归成四件事:

1. **把订单收进来**(OMS-1 手工/Excel/API/门户、OMS-5 PDF 读单)——来源多,格式乱,但进系统后必须长一个样;
2. **让订单能被跟踪**(OMS-2 状态、OMS-10 客户查单)——员工和客户看到的是同一条时间线;
3. **让订单能被改**(OMS-4 改单取消、OMS-8 拆单/backorder、OMS-9 退货)——B2B 的现实是订单一直在变;
4. **让订单知道自己值多少钱、能不能发**(OMS-3 运输报价与服务选择、OMS-7 在库校验、OMS-11 财务锁)——下单时看到预计费用与服务等级,发货前先过财务这一关。

## 3.2 同行业产品研究 —— 按强项分项对标

OMS 不是一家公司能教完的。下面把订单模块拆成六个能力,每项单独对标该能力最强的产品,只采用其核心机制,并写明落到我们的哪个设计。

### 3.2.1 能力对标表

| 能力 | 对标 | 优势 | 我们的设计决定 |
|---|---|---|---|
| **乱格式订单接入** | **CartonCloud** | 出身自家 3PL,默认订单是从邮件/PDF/Excel 乱飞进来的,把"自动录单"做成卖点而非杂活 | 四条来源(手工 / Excel / API / PDF)→ 一张 `orders` 表,`source` 只是字段;OMS-5 PDF 读单保留人工确认兜底 |
| **订单类型分离** | **CartonCloud** | 入库单 / 出库单 / 运输 consignment 是三种单据,不是一张表加标记 | `order_type` = 出库派送 / 纯运输 / 退货;入库(ASN)整体交给 WMS,不进 OMS |
| **统一接入与客户规则** | **Extensiv**(Order Manager) | 多渠道订单标准化后,按**客户规则**自动处理(路由、拆单、backorder),规则可配置不写死 | 拆单/backorder 规则(OMS-8)做成每客户可配:缺货时"整单等" / "先发可发" / "拆成子单";存在 `clients.order_rules` |
| **单据链与利润归集** | **CargoWise** | Job 为中心:订单、仓储、运输、费用挂同一个 Job,毛利实时可见 | 订单号是主线,`shipment` / `charge` / `carrier_cost` 全部可回溯到订单;订单详情页显示"收 − 付 = 毛利" |
| **客户可操作的订单视图** | **CargoWise Neo** | 客户不只是查,还能下单、传单据、看费用,日常自己完成 | PLT-3 门户 + OMS-10:客户能下单、上传装箱单、查时间线、下载 POD 和账单 |
| **一票货的时间线呈现** | **Magaya LiveTrack** / Flexport | 一票货的节点、单据、费用在一个页面,不用在多个菜单里翻 | 订单详情页 = 一条时间线,每个节点挂着当时产生的单据与费用 |

## 3.3 数据模型

```
orders                     一次配送或操作指令(不是 Job;一个 Job 下可有多张)
├─ id, order_no            单号 ORD-20260901-0001
├─ client_id               委托客户(买方或卖方,PRD "Clients (buyers/sellers)")
├─ order_type              from_stock 出库派送 | pickup_deliver 纯运输(OMS-6) | return 退货(OMS-9)
├─ source                  portal | excel | api | manual | pdf(OMS-1/5)
├─ external_ref            客户自己的单号(去重用)
├─ consignment_mark        唛头(真实清单的分组键)
├─ job_id                  所属 Job(业务主线;OMS-12 入库批次关联由 Job 实现)
├─ fba_reference           FBA Shipment ID(可空)
├─ pickup_address          纯运输时的取货地址(OMS-6)
├─ (declared_packages)     申报包裹(见下):初步报价与纯运输报价的依据
├─ deliver_to_*            收件人姓名/电话/地址/州/邮编
├─ requested_date          要求送达日
├─ operational_status      见 §3.4(received … delivered / returned / cancelled)
├─ fulfilment_status       unfulfilled | partial | fulfilled(由 fulfilments 推导)
├─ billing_status          unbilled | partially_billed | billed | credited(由 charges / invoices 推导)
├─ (hold)                  挂起不是字段,是 holds 记录(见下),可多条并存
├─ service_level           standard | express | same_day(OMS-3)
├─ tailgate_required      是否需要尾板车(OMS-13,自动判定可人工覆盖)
├─ tailgate_reason        判定原因:单件超重 / 住宅地址 / 人工指定
├─ customer_quote_id       引用客户报价单(OMS-3):明细行含拆柜 / 上架 / 预计仓储 / 拣货 / 运输 / 尾板附加 / GST / 假设条件
└─ created_by, timestamps

order_lines                货物明细(真实清单的列)
├─ order_id
├─ description_cn / description_en      中英品名
├─ hs_code, material, usage, brand      海关字段(只存不处理)
├─ package_type                         外包装种类
├─ carton_qty, unit_qty                 箱数 / 数量
├─ unit_price, total_price              货值 AUD(报关用,非我们的收费)
├─ actual_weight, length, width, height, cbm
├─ qty_shipped, qty_backordered         履行进度(按 fulfilments 汇总,OMS-8)
├─ asn_line_id                          由 ASN 生成时指向来源货物行(一份文件只录一次)
└─ stock_unit_ref                       关联在库批次(WMS 提供)

fulfilments                履约批次:一张订单可以分多次、跨多仓发(OMS-8)
├─ id, order_id, seq       ORD-...-F1 / F2
├─ warehouse_id            本批次从哪个仓发(墨尔本 60 箱 + 悉尼 40 箱 = 两个批次)
├─ status                  allocated | picking | packed | dispatched | delivered
├─ fulfilment_lines        order_line_id, qty
└─ shipment_ref            对应 TMS 的 shipment

declared_packages          申报包裹(来自清单或客户填写;初步报价依据;纯运输的最终依据)
├─ order_id, package_type, qty, weight, length, width, height
└─ 打包后的实测包裹在 WMS 的 packages 表,两者分开;运费最终按实测(纯运输按申报,司机取货可复核)

holds                      订单挂起(替代单字段 hold_reason;复用 Platform exceptions 表,type = hold)
├─ order_id, hold_type     stock | financial | address | transport | client_confirmation
├─ status                  active | released
├─ owner_id, created_at, released_by, released_at, release_reason
└─ 规则:任一 active 的 financial hold 存在 → 不得预订 / 发运(OMS-11);拣货打包不受财务锁影响

client_addresses           客户收件地址簿(OMS-14)
├─ client_id, label        别名,例如 "FBA BWU2"、"墨尔本仓"
├─ contact_name, phone
├─ address, suburb, state, postcode
├─ address_type            business | fba | residential(影响尾板判定)
├─ default_instructions    固定送货备注(预约要求、卸货门号等)
└─ usage_count, last_used_at   下单时按使用频率排序

order_events               时间线(只增不改)
├─ order_id, from_status, to_status
├─ actor(用户或系统事件), note, created_at

order_documents            订单附件:客户上传的装箱单/PDF、系统生成的单据
order_imports              每次 Excel/PDF 导入的记录 + 行级报错,可回溯
```

**关键决定:** 货物信息全在 `order_lines`;订单行通过 `asn_line_id` 关联库存(货物行是库存身份);`consignment_mark` 只用于分组、搜索与客户参考。

## 3.4 状态机 —— 内部细状态 + 客户粗状态

PRD 写的是给客户看的 6 步:`Received → Confirmed → In warehouse → Out for delivery → Delivered → Invoiced`。仓库实际要更细。方案是**一套内部状态,一张映射表决定客户看到什么**:

| 内部状态 | 客户看到 | 谁推动 |
|---|---|---|
| `received` 已接收 | Received | 系统(导入/门户/API) |
| `confirmed` 已确认 | Confirmed | CS / Coordinator |
| `allocated` 已配货 | In warehouse | 系统(关联在库批次) |
| `picking` 拣货中 | In warehouse | WMS |
| `packed` 已打包 | In warehouse | WMS |
| `dispatched` 已发运 | Out for delivery | TMS |
| `delivered` 已送达 | Delivered | TMS(POD) |
| `returned` 已退回 | Returned | WMS(退货验收完成) |
| `cancelled` 已取消 | Cancelled | CS(带权限) |

上表是**运营状态**。客户看到的 "Invoiced" 不由运营状态给出,而由 **billing_status** 推导(unbilled / partially_billed / billed / credited);**fulfilment_status**(unfulfilled / partial / fulfilled)由履约批次推导;**挂起**由 holds 记录表示。四个维度独立,客户粗状态 = 运营状态 + 开票状态合成。

- 只有 OMS 拥有订单状态字段的写入权;WMS/TMS 通过 `domain_events` 通知,OMS 消费后推进状态 —— 保持模块所有权铁律。
- 异常与挂起不是状态,是 `holds` 记录(类型、负责人、创建与解除),可多条并存、叠加在任意状态上。
- 改单/取消规则(OMS-4):**锁点是"开始拣货"** —— `received` / `confirmed` / `allocated` 自由改(配货只是预留,货还没动);进入 `picking` 后需 Coordinator 或主管审核并留原因;`dispatched` 后只能走 OMS-9 退货。
- **财务锁(OMS-11)**:**仅人工置锁**。Finance / Coordinator 可对客户或订单置 financial hold(原因必填);存在 active hold 时订单可以确认、配货、拣货、打包,但**不允许预订与发运**(锁点在 booking / dispatch);放行由 Finance / Coordinator 人工操作,记录放行人与原因,写入时间线。**系统不按收款状态自动置锁**(2026-09-07 决定):账期按客户配置(prepaid / eom / net_N),发票逾期只在列表标红,不阻止预订与发运。
- **尾板车判定(OMS-13)**:确认订单时按规则自动判定并写入 `tailgate_required`;**费用不在此产生**,只在 shipment 预订时由 charge rule 产生一次;人工可覆盖(需填原因)。规则:任一单件重量 ≥ 客户配置阈值(默认 25kg),或收件地址类型为 residential,或人工指定。
- **一张订单可分多次、跨多仓履约(OMS-8)**:订单本身不拆,每个履约批次记仓库,状态由 `fulfilments` 汇总推导(全部送达才 `delivered`);客户看到的是一张订单 + 多个发货批次,对账口径不乱。
- **退货全链路(OMS-9)**:return_requested(OMS)→ return_in_transit(TMS 退货运输)→ arrived_warehouse → inspected → restocked / quarantined / damaged(WMS return_receipts)→ financial_decision(Billing:credit note 或不予 credit)。司机标记退回不触发库存或 credit;订单运营状态在验收完成后变为 `returned`。

## 3.5 屏幕清单(Blade SSR,越简单越好)

| 屏幕 | 用途 | 对应 PRD 需求 |
|---|---|---|
| 订单列表 | 筛选(客户/状态/日期/唛头/收件州)、批量确认、导出 | **OMS-2 订单状态** — 员工与客户随时能看到订单走到哪一步、历史如何 |
| 订单详情 | 头部信息 + 明细 + **时间线** + 关联发运/POD/费用 + 毛利 | **OMS-2 订单状态**、**OMS-10 客户查单** — 一票货的节点、单据、费用在同一页 |
| 新建订单 | 单页表单,选客户 → 选类型 → 填收件人 → 加明细行 | **OMS-1 订单录入** — 客服手工建单;记录客户、收货地址、货物数量、要求送达日 |
| Excel 导入 | 上传 → 预览(按唛头分组)→ 行级报错 → 确认导入;用于无 ASN 的尾程委托。有 ASN 的货走"从 ASN 生成订单"(§4.4),一份文件只录一次 | **OMS-1 订单录入** — Excel 上传这一条路径,按真实《需派送货物清单》模板 |
| PDF 读单 | 上传 → 解析结果人工校对 → 生成草稿单 | **OMS-5 自动读单** — 邮件/PDF 采购单自动读成草稿单,人工确认后成单(后期功能) |
| 改单/取消 | 弹窗式,必填原因 | **OMS-4 改单与取消** — 开始拣货前自由改,之后需主管审核并留痕 |
| 分批履约 / backorder | 选行、填本批可发数量、生成履约批次(订单不拆) | **OMS-7 在库校验**、**OMS-8 分批发货与缺货单** — 有多少先发多少,余量等新货到再发 |
| 退货 | 从原单发起,填原因、数量、去向(good/damaged) | **OMS-9 退货** — 收货方拒收或退回时记录原因、退回库存、标记应给的 credit |
| 财务锁 / 放行 | 人工置锁与放行(原因必填),锁定订单在列表中高亮 | **OMS-11 财务锁**(新增) — 仅人工置锁;收款状态不自动锁,逾期只标红 |
| Coordinator 队列 | 订单列表的预设视图:待确认 / 缺货 / 财务锁 / 异常 / 今日待发 | **OMS-2 订单状态** — 调度员统一管理待处理订单与异常,不必自己记 |
| Job 视图(入库批次) | 从 Job 工作台看该 Job 的 ASN、生成了哪些订单、发完没有、毛利多少 | **OMS-12 入库批次关联**(新增,由 Job 实现) — 一次进口的货能整批追踪与结算 |
| 客户地址簿 | 客户常用收件地址维护(内部与门户共用),按使用频率排序、一键带入 | **OMS-14 收件地址簿**(新增) — 反复发同几个地址(尤其 FBA 仓)时免重复输入、减少地址错误 |
| 门户下单 | 客户版新建订单(字段更少) | **PLT-3 客户门户**、**OMS-1 订单录入** — 客户自助下单这一条路径 |
| 门户查单 | 客户版列表 + 详情(只读,含 POD 下载) | **OMS-10 客户查单** — 客户按单号/参考号/日期/状态查自己的订单,看轨迹与签收凭证 |

## 3.6 Excel 导入规则(按真实《需派送货物清单》)

1. 读表头,容忍列顺序变化和中英文表头;不认识的列忽略但保留在 `raw_json`;
2. **按"唛头 + 收件地址 + FBA 引用"分组**:同组的行合并成一张订单;同一唛头下地址或 FBA 引用不一致时**阻断导入并要求人工确认**,不再默认取第一行;
3. 校验:客户必填且存在、收件地址完整、箱数/重量为正数、唛头非空;
4. 去重:`client_id + external_ref` 或 `client_id + 唛头 + 收件人 + 日期` 重复时提示"已存在,是否跳过";
5. 结果页:成功 N 张订单 / 失败 M 行(每行给出原因),失败行可下载修正后重传;
6. 导入时可指定**所属 Job**(柜业务即该柜的 Job),该批订单自动带上 `job_id`(OMS-12);
7. 导入时若收件地址与地址簿匹配,自动带出地址类型与固定备注(OMS-14);不匹配则提示是否存为新地址;
8. 全过程写入 `order_imports`,可追溯谁在什么时候导了什么;
9. **同一份清单只录一次**:若该 Job 已有 ASN 且货物已上架,不走本页导入,改用 WMS 的"从 ASN 生成订单"(B2c);本页导入前检查该 Job 是否存在同一唛头的 ASN 行,有则提示改用生成而非重录。

## 3.7 任务分解(账号 A · 版块 A-2)

> 估时单位 = 账号·人日。DoD:migration + 页面 + Feature 测试 + 中文 lang 文案 + 提交到版块分支。

### 3.7.1 任务概览

| # | 任务 | 依赖 | 对标 |
|---|---|---|---|
| A3 | 订单模型 + 状态机 + 列表/详情/时间线 + 手工建单 | A2 | Magaya 时间线 · CargoWise 单据主线 |
| A4 | Excel 导入(唛头分组、行级报错、去重) | A3 | CartonCloud 乱格式接入 |
| A4b | 订单 API 接入(接口预留) | A3 | Extensiv 统一接入 |
| A7 | 在库校验(按货物行)+ 自动拆出可发部分 + 履约批次(记仓库) | A3, B1 | Extensiv 客户规则 |
| A7b | 客户报价单(customer_quotes)+ 初步估价:调 TransportOptionService 出初步方案与服务费预估,明细行存单 | A3, A5, B5c | CargoWise 报价 · Shippit 服务等级 |
| A11 | 改单/取消权限 + 退货全链路(申请 → 运输 → 验收 → 财务决定) | A3, B1, B13 | CartonCloud 改单权限 · 退货验收 |
| A11b | 纯运输订单 | A3 | CartonCloud 订单类型分离 |
| A9-p | 门户下单 + 门户查单 | A3, A1 | CargoWise Neo 客户工作台 |
| A13 | 财务锁 / 放行(holds 记录;仅 Finance / Coordinator 人工置锁与放行,原因必填;锁预订 / 发运;不按收款状态自动触发) | A3 | 货代"付款后放货"(只取人工锁) |
| A14 | 入库批次关联 | A3, B2 | CargoWise Job 归集 |
| A15 | Coordinator 队列 | A3 | CartonCloud live queue |
| A16 | 尾板车自动判定 | A3, A5 | TransVirtual · MachShip |
| A17 | 客户收件地址簿 | A3 | CargoWise Neo · Magaya |
| A12 | PDF/邮件读单 | A3 | CartonCloud 自动录单 |

### 3.7.2 每个任务满足哪条需求

**A3 · 订单模型 + 状态机 + 列表/详情/时间线 + 手工建单**

`依赖 A2 · 对标:Magaya 时间线、CargoWise 单据主线`

- **OMS-1 订单录入** — 客服可手工建单;每张订单记录客户、收货地址、货物与数量、要求送达日。
- **OMS-2 订单状态** — 订单按固定步骤推进,员工与客户随时能看到当前状态与完整历史。

**A4 · Excel 导入(唛头分组、行级报错、去重、导入记录)**

`依赖 A3 · 对标:CartonCloud 乱格式接入`

- **OMS-1 订单录入** — 其中的"Excel 上传"路径。按真实《需派送货物清单》模板,按"唛头 + 收件地址 + FBA 引用"把多行货物合并成一张订单,同组地址不一致则阻断。用于无 ASN 的尾程委托;有 ASN 的货由 WMS 的 B2c 从 ASN 生成,解析器共用。
- `OrderService::createFromAsn()` 对 WMS 开放,建单逻辑与手工 / Excel / API 完全一致(统一走 OrderService,是接口预留的直接受益者)。

**A11b · 纯运输订单(取货地址、跳过仓库直接进 TMS)**

`依赖 A3 · 对标:CartonCloud 订单类型分离`

- **OMS-6 纯运输任务** — 不进仓库的任务:从客户处取货直接送达;订单记录任务类型、取货地址与 declared_packages;确认即触发运输报价(不等 WMS 事件),申报包裹即最终依据。

**A13 · 财务锁 / 放行(置锁、放行、阻止进入拣货、时间线留痕)**

`依赖 A3 · 对标:行业通行做法(先款后发)`

- **OMS-11 财务锁**(新增,PRD 无对应条目)— 人工置锁时不得预订 / 发运;放行需财务或调度并留原因。不按押金、信用额度或收款状态自动触发(2026-09-07)。最接近的现有条目是 **PLT-7 审批**(敏感动作需第二人批准)。

**A14 · 入库批次关联(订单带柜号/入库单号,按批次看订单与毛利)**

`依赖 A3、B2 · 对标:CargoWise Job 归集`

- **OMS-12 入库批次关联**(新增,PRD 无对应条目)— 一次进口的货(整柜或散货)拆成多张派送单后,要能整批追踪与结算;与 WMS 的 ASN 对齐。

**A9-p · 门户下单 + 门户查单(同一套页面、两种权限视图,不写两套)**

`依赖 A3、A1 · 对标:CargoWise Neo 客户工作台`

- **OMS-10 客户查单** — 客户按单号、参考号、日期或状态查自己的订单,看明细、当前状态、配送轨迹与签收凭证;只能看到自己的订单。
- **PLT-3 客户门户** — 其中的"客户自助下单"路径。
- **PLT-1 用户与权限** — 客户数据隔离由数据层全局 scope 保证。

**A7 · 在库校验 + 自动拆出可发部分、履约批次与 backorder(按客户规则)**

`依赖 A3、B1 · 对标:Extensiv 客户规则`

- **OMS-7 在库校验** — 下单时检查客户可用库存,不足时警告。
- **OMS-8 分批发货与缺货单** — 有货的部分先发,余量作为 backorder,新货到后自动履行。订单本身不拆,拆的是履约批次。

**A7b · 运输报价与服务选择(选服务等级 → 出价 → 快照存单)**

`依赖 A3、A5 · 对标:CargoWise 利润归集`

- **OMS-3 运输报价与服务选择** — 下单时调 TransportOptionService 返回自派 / Transdirect / EIZ 多个方案(标 Recommended / Cheapest / Fastest),客户确认推荐或改选,选定方案快照存单;与 **FIN-6 报价** 共用同一套计算。

**A15 · Coordinator 队列(订单列表的预设筛选视图)**

`依赖 A3 · 对标:CartonCloud live order queue`

- **OMS-2 订单状态** — 调度员统一管理待处理订单与异常:待确认 / 缺货 / 财务锁 / 今日待发。

**A16 · 尾板车自动判定(按重量阈值、住宅地址自动勾选,可人工覆盖;费用在预订时产生)**

`依赖 A3、A5 · 对标:TransVirtual、MachShip 行业通行`

- **OMS-13 尾板车判定**(新增,PRD 无对应条目)— 重货或住宅地址需要尾板车;漏判会导致司机白跑与费用漏收。阈值与费用在客户价目表里配。

**A17 · 客户收件地址簿(内部与门户共用,按频率排序、一键带入)**

`依赖 A3 · 对标:CargoWise Neo、Magaya`

- **OMS-14 收件地址簿**(新增,PRD 无对应条目)— 客户反复发往同几个地址(尤其 FBA 仓),重复输入且易错;地址类型字段同时为 OMS-13 判定供数。

**A11 · 改单/取消分阶段权限 + 退货(退库事件 + credit 标记)**

`依赖 A3、B1 · 对标:CartonCloud 改单权限`

- **OMS-4 改单与取消** — 仓库作业开始前可自由改或取消,之后需主管审核并记录。锁点定为"开始拣货"。
- **OMS-9 退货** — 收货方拒收或退回时记录原因、退回客户库存(或损坏库存)、标记应给的 credit。

**A4b · 订单 API 接入(token 鉴权、幂等)—— 接口预留**

`依赖 A3 · 对标:Extensiv 统一接入`

- **OMS-1 订单录入** — 其中的"客户自有系统推单"路径。本期不接真实客户,只把口留好:建单统一走 `OrderService`,API 只是一层控制器,以后加只需半天。

**A12 · PDF/邮件读单 → 草稿单人工确认**

`依赖 A3 · 对标:CartonCloud 自动录单`

- **OMS-5 自动读单** — 邮件或 PDF 采购单自动读成**草稿单**由员工确认,免去重复录入。PRD 自己写明这是"核心系统上线后的后期功能",所以优先级是 PRD 给的,不是我们定的;因要求人工确认,解析准确率不作验收项。

## 3.8 验收标准(OMS 部分)

1. 导入真实《需派送货物清单》→ 按"唛头 + 收件地址 + FBA 引用"生成正确张数的订单,同一唛头下地址不一致的行被阻断并提示,FBA 单带上 FBA 引用,错误行有可读原因;
2. 同一份文件重传 → 提示重复,不产生重复订单;
3. 一张订单运营状态从 `received` 走到 `delivered`、开票状态从 `unbilled` 走到 `billed`,两条线分别验收;时间线记录每一次状态变化和操作人;
4. 已发运的订单无法被编辑,只能走退货;
5. 客户账号登录门户,只能看到自己的订单,能下单、查状态、下载 POD;
6. 纯运输订单不经过仓库环节,直接出现在 TMS 待派清单里;
7. 被人工置财务锁的订单可以确认、配货、拣货、打包,但点"预订 / 发运"时被拒绝,放行后可继续,时间线记录放行人;
8. 一张订单分两次发货后,客户看到的仍是一张订单 + 两个发货批次,全部送达后订单才变成已送达;
9. 按柜号或入库单号查询,能看到该批货生成的全部订单、发货进度与合计毛利;
10. 录入一件 25kg 的货物,订单自动勾选尾板车;预订车辆后 charges 出现一条且仅一条尾板费;人工取消需填原因,原因写入时间线;
11. 客户第二次向同一 FBA 仓下单时,地址可从地址簿一键带入,固定备注自动填好。

## 3.9 已确认决定

**已确认(2026-08-31,本计划据此锁定):**

| # | 决定 | 影响 |
|---|---|---|
| **Q1** | 货物信息全在 `order_lines`,库存按客户 + 货物行 + 包装单元 + 库位 计箱/托 | 在库校验按货物行调 `StockService::onHand(client, asnLineId)`;唛头只做分组、搜索与客户参考 |
| **Q2** | **只做 B2B** —— 收件人是企业地址(含 FBA 仓),不做个人小包 | OMS-1 描述里的 "B2B/B2C" 字样应删除;订单模型不加个人收件人字段;TMS 不做快递面单 |
| **Q3** | **OMS-3 定价 = 服务费预估** —— 按客户价目表估算仓储/操作/运费,下单时可见 | 任务 A7b 调 `RateService` 做预览;订单行里的 `unit_price / total_price`(货值 AUD)只存不算,仅供报关与保险参考 |

**v1.3 新增决定(据你 2026-08-31 的确认):**

| # | 决定 | 说明 |
|---|---|---|
| **OMS-11** | 财务锁 / 放行 | 仅人工置锁(Finance / Coordinator,原因必填);锁点在预订 / 发运前;人工放行需留原因;不按收款状态自动锁 |
| **OMS-12** | 入库批次关联 | 由 Job 主线实现:订单挂 `job_id`,同一 Job 下的 ASN(含可选 Container)、订单、发运、费用、成本一处可见;不限整柜,散货入库同样适用 |
| **OMS-8 改法** | 订单不拆,拆履约批次 | 客户始终看到一张订单 + 多个发货批次,对账口径不乱 |
| **OMS-4 锁点** | 改为"开始拣货" | 配货只是预留,货未动;拣货才是不可逆点(这是改单锁,与财务锁的预订 / 发运锁点不同) |
| **OMS-3 改名** | 运输报价与服务选择 | 选服务等级 → 出价 → 报价快照存单,与 FIN-6 共用 |
| **OMS-1 API** | 降为 P2,接口预留 | 建单统一走 `OrderService`,API 只是控制器,后加只需半天 |
| **架构不变** | OMS 与 WMS/TMS 通过 `domain_events` 联动 | 不互相写表 —— 这是两账号并行开发的前提 |
| **OMS-13** | 尾板车自动判定(行业建议采纳) | 单件 ≥ 阈值(默认 25kg)或住宅地址自动勾选 `tailgate_required`,可人工覆盖;费用不在此产生,由最终报价确认时的 charge rule 产生一次;阈值与费用在客户价目表里配 |
| **OMS-14** | 客户收件地址簿(行业建议采纳) | 内部与门户共用,按使用频率排序;含地址类型(business / FBA / residential),同时为尾板判定提供依据 |

**OMS 章节无待确认项。** 状态机映射(内部 9 个运营状态、客户看 6 个粗状态,§3.4)视为已接受;退货 credit 已定为只在 `return.financial_decision` 触发(v4.1)。以下是**PRD 文档**需要同步的修改,不属于本计划,由人处理:WMS-5 删除"后期功能"字样;WMS-9 移除;OMS-11 ~ OMS-14 补入;FIN-3 发票口径改为"费用归集到 Job、可跨 Job 合并、账期按客户配置(prepaid / eom / net_N),不因未收款卡预订";Not Included 第一条与孤立的 "Finance" 行;词汇表 1PL–4PL 条目改回只解释 3PL。

---

---

# 4. WMS 仓库模块(账号 B)

## 4.1 能力研究与取舍

### 4.1.1 多货主库存隔离 —— CartonCloud

**优势:** 见 §1.2。具体到库存模型:同一件货品存给客户 A 和客户 B 是两条独立记录,不能互相调拨;库存查询、盘点、计费全部按客户切分。

**取舍:** **原样采用**,键值换成适合我们的形态 —— 库存单元 = **客户 + 货物行(asn_line)+ 包装单元(托 / 箱,带系统箱标)+ 库位**,计箱/托。仍不建 SKU 主档,货物行本身就是身份。粒度必须到货物行而不是唛头:真实清单里同一唛头下常有多行不同货物(如 GD20260506BC 下三行),只按唛头聚合会把它们合并、拣货时分不开。

### 4.1.2 整柜拆柜(container devanning)—— Magaya

**优势:** Magaya 服务迈阿密一带的 NVOCC、CFS 和货代,那里的日常工作就是"一个柜进来、拆成几十个货主的货、再重新组合发出去"。所以它把**柜当成一级单据**:柜有自己的号、自己的到港与拆柜生命周期、自己的费用项(devanning、CFS handling),拆柜差异逐票记录。对比之下,电商仓 WMS 里"柜"通常只是收货单上的一个备注字段,拆柜费根本无处可挂。

**取舍:** **只取"柜是 ASN 下的可选物理对象"这一点,不取柜级生命周期**(2026-09-07 决定:按客户价目表的基础功能做,不照搬 CargoWise / Magaya 的货柜管理)。整柜入库时创建 `containers` 记录,只保留计费需要的字段:柜号、20/40ft、拆柜方式(pallet / loose / mixed)、毛重(Cartage 的 22.5 吨门槛)、货物行数(价目表的"within 20 SKU"用该柜下 asn_lines 行数代替);散货、卡车、包裹入库不创建。拆柜以一条 `devanning` 任务作为拆柜费的触发点。**舍弃** 封条号、拆柜起止时间、柜级差异备注与单证,以及报关、保税、CFS 法规功能 —— 那是货代的事,我们从货到澳洲之后开始。

### 4.1.3 任务化作业下发 —— Microlistics

**优势:** 企业级 WMS 的做法是**系统决定工人下一步做什么**:收货、上架、拣货、补货都拆成任务,按规则和库位路径下发到手持设备,工人只管执行、完成即回写。好处有二:新人不需要懂业务就能上工,以及每个动作都有时间戳可度量。

**取舍:** **简化采用** —— 拣货单不是一张纸,而是一串按库位排序、逐条确认的任务;拆柜、扫描、人工时等 VAS 作业以统一 `warehouse_tasks` 记录,作为执行凭证与计费触发点(真实价目表里这些都是收费项,没有任务记录 Billing 只能靠人猜)。**舍弃**规则引擎、任务优先级调度、RF 硬件假设。理由(判据三):我们的仓库规模下"按库位排序 + 逐条确认"已拿到八成收益。

### 4.1.4 波次与拣货路径 —— Logiwa

**优势:** Logiwa 面向高单量 DTC 履约,核心是波次算法与拣货路径优化,把工人每天的走动距离压下来,单量越大收益越明显。

**取舍:** **只采用"波次"这个概念,不采用算法**。我们的波次 = 按客户/承运商/送达日筛一批订单一起释放;排序按库位编码,不做路径优化。理由(判据一):路径优化解决"每天几万单"的问题,我们没有;而且算法要真实动线数据才调得准,现在没有。

### 4.1.5 库存流水可对账 —— Extensiv

**优势:** Extensiv 的 3PL 计费建立在库存准确之上,所以每笔移动写成**不可变流水**,库存余额永远等于流水累加。这带来一个很实用的性质:任何库存争议都能回放到某一笔动作、某个操作人。

**取舍:** **原样采用**。`stock_ledger` 只增不改,加一条 `stock:reconcile` 对账命令,差异必须为零。

### 4.1.6 快照即计费依据 —— Extensiv / CartonCloud

**优势:** 仓储费的争议点永远是"到底存了多久、存了多少"。两家的做法都是每日快照,快照是计费的唯一依据,可回查任意一天;费率变了、库存动了,历史账单也不会变。

**取舍:** **采用,但周期按真实价目表改成"周"** —— 真实价目表是**托盘·周**计费,不是按天。每日快照照做(供回查与争议处理),出账按周汇总,并按托盘类型(标准/超宽/超高/超重/pickface)分别计价。

### 4.1.7 损坏与隔离 —— CartonCloud

**优势:** 损坏货如果只是打个标记还留在可用库存里,迟早被拣走发出去。CartonCloud 把它移入独立状态,带原因和照片,不进可用库存,直到处理掉。

**取舍:** **采用**,加照片(与 POD 共用上传组件)。**隔离与损坏货照收仓储费(已定,行业通行做法:占了库位就计费)**,但用独立 charge code(如 WH-STORAGE-PLT-WK-QUAR)在账单上单列,客户一眼能看到;货物报废移出库存后停止计费。

### 4.1.8 分配与预留(Allocation / Reservation)—— Extensiv / CartonCloud

**优势:** 3PL WMS 把"锁库存"做成独立步骤:订单确认后由仓库真正预留具体库存单元,拣货前库存已归属该订单;订单取消或减量自动释放。没有这一步的系统会出现"账面有货、实际被别的单占了"的假缺货。

**取舍:** **原样采用,并明文化**。预留由 WMS 写、OMS 消费:`order.confirmed → stock.reserved | stock.reservation_failed`;`order.cancelled | order.reduced → stock.released`。

### 4.1.9 条码与扫码 —— CartonCloud

**优势:** 见 §1.4。CartonCloud 的收货、上架、拣货、盘点全部可扫码完成,扫的是系统自己打印的标签(库位码与货物标签),不依赖供应商条码。

**取舍:** **原样采用,列为核心范围(不是后期功能)**。两种输入并存:扫码枪(键盘输入,所有表单原生支持)与手机摄像头扫码页;收货时打印系统箱标,库位打印库位条码。PRD WMS-5 的"后期功能"字样应同步移除。

### 4.1.10 明确不采用的

| 不采用 | 谁 | 理由 |
|---|---|---|
| SKU / 产品目录驱动的库存 | CartonCloud · Extensiv · Logiwa | 判据二:依赖"货品是可重复销售的商品";我们的货是一次性进口批次 |
| 批次/效期与 FIFO/FEFO | Extensiv · Logiwa | PRD 有 WMS-6,但当前客户货物不涉及食品美妆;字段预留,不做功能 |
| 补货 / 库位优化 / 上架建议 | Logiwa · Microlistics | 判据一:解决长期存货、反复拣选的问题;我们是批次进出 |
| 电商平台连接器 | Extensiv · Logiwa | 判据二:客户是货代与企业,不是网店 |
| 低库存提醒(PRD WMS-9) | — | **删除**:3PL 不负责客户补货,批次货发完即结束;PRD 应同步移除 |
| 柜级生命周期 / 拆柜差异单证 / 封条 | Magaya · CargoWise | 2026-09-07 决定:只做价目表要的基础字段(柜号、柜型、拆柜方式、毛重、行数) |

## 4.2 数据模型(owner:账号 B)

```
warehouses                 仓库:code, name, address, state, active

locations                  四级库位码:warehouse / zone / aisle / bin → full_code
└─ type                    receiving | storage | pickface | packing | staging | quarantine(pickface 按占用格数计周费)

asns                       入库主单(挂 Job;收货 / 差异 / 上架 / 库存由它管理;Container 为其下可选对象,只记基础字段)
├─ asn_no, job_id, client_id, expected_date
├─ inbound_type            container | loose_truck | parcel
├─ status                  booked → arrived → receiving → putaway → closed
├─ created_by_type         client(门户预告)| coordinator(内部建单)
└─ unplanned               无预报到货:临时收货单,需 Coordinator 确认后才可上架

containers                 整柜入库才创建(ASN 下的可选物理对象,0..n;只记计费需要的基础字段,不做柜级生命周期)
├─ asn_id(主)、job_id(冗余,便于按 Job 查)
├─ container_no, size(20 | 40)
├─ unpack_mode             pallet | loose | mixed(mixed 为 POA → 待报价)
├─ gross_weight_kg         Cartage 门槛(默认 22.5 t,超过 → 待报价;阈值在价目表)
└─ line_count              该柜下 asn_lines 行数(派生;价目表"within 20 SKU"用它判断,超上限 → 待报价;上限在价目表)

asn_lines                  货物行(库存的身份来源)
├─ asn_id, container_id(可空)   来自哪个柜;一行货只属一个柜,跨柜拆行
├─ consignment_mark        唛头(分组用,不是库存键)
├─ deliver_to_*, fba_reference   收件人与 FBA 引用原样保留(供生成订单;导入时预检同唛头地址是否一致)
├─ description, package_type
├─ expected_cartons, received_cartons, damaged_cartons, variance_reason
└─ weight, dims, cbm

stock_units                库存单元 = 客户 + 货物行 + 包装单元 + 库位
├─ client_id, job_id, asn_line_id     货物行即身份(无 SKU 主档)
├─ unit_type               pallet | carton
├─ label_code              系统箱标 / 托标条码(扫码对象)
├─ location_id
├─ qty_on_hand, qty_reserved, qty_inbound   按箱计;pallet 单元记所含箱数;available = on_hand − reserved(可部分预留)
├─ pallet_class            standard | oversize_wide | oversize_high | overweight | pickface(仓储计费用;系统按**客户价目表**里的尺寸 / 重量阈值建议、收货人确认可改;超出所有档位 → POA 待报价)
├─ length_mm, width_mm, height_mm, weight_kg   托盘单元实测(收货录入;pallet_class 建议、800 kg 门槛与 TMS 托盘运费的依据)
├─ pallet_source           client_own | warehouse_plain | chep | loscam(托盘单元;驱动托盘租赁 per pallet·week 与仓库供应托盘的一次性购买费)
├─ condition               good | quarantine | damaged(物理状态;预留不是 condition)
└─ received_at             FIFO 与仓储费起算

warehouse_tasks            作业任务(拣货任务与 VAS 执行凭证;拣货单并入此表)
├─ task_no, job_id, client_id, warehouse_id
├─ task_type               receiving | putaway | move | pick | pack | load | count | return_inspection
│                          | devanning | wrap | scanning | labour | waste | vas_other(wrap 按 source_type 分进库 / 出库 code)
├─ source_type / source_id 来源单据(order / fulfilment / asn / container / stocktake / wave)
├─ order_id, fulfilment_id, asn_id, container_id(便于按单据查)
├─ priority, assigned_user_id, status(pending | in_progress | done | cancelled | exception)
├─ exception_reason, cancel_reason
├─ billable_qty, billable_uom   计费数量与单位(container | pallet | carton | scan | man_hour | cbm | label);VAS 由此计费
├─ hours_business, hours_after_hours   labour 任务:班内 / 班外小时数(主管手填,按仓库营业时间配置区分,不按时间戳推算)
├─ started_at, completed_at
└─ billable_event_id       完成时发出的事件(供 Billing)

warehouse_task_lines       任务明细(拣货:每行一个库存单元与数量;收货:每行一条 asn_line)
└─ task_id, stock_unit_id / asn_line_id, location_id, required_qty, completed_qty, confirmed_at

scan_records               序列号扫描记录(每次扫描一行;数量 = 行数,即 per scan 计费依据;可印到 packing list)
└─ task_id, stock_unit_id / package_id, serial_no, scanned_by, scanned_at

return_receipts            退货收货与验收(只有验收完成才增加库存)
├─ job_id, return_order_id, original_order_id, original_shipment_id, return_shipment_id
├─ status                  expected → received → inspected → closed
└─ completed_at

return_receipt_lines       退货明细(恢复库存的依据)
├─ return_receipt_id, original_order_line_id, asn_line_id, original_fulfilment_id
├─ expected_qty, received_qty, condition
└─ disposition(available | quarantine | damaged) → 生成对应库存单元与流水(movement_type = return)

stock_ledger               只增不改
├─ stock_unit_id, movement_type(receipt | putaway | pick | transfer | adjust | release | return | split | merge)
├─ qty, qty_before, qty_after
├─ movement_group_id       同一次拆分 / 合并 / 移库的多条流水共用一个组号
├─ from_stock_unit_id, to_stock_unit_id   拆托:from = 托盘单元,to = 新箱单元;并托反之
├─ from_location_id, to_location_id       移库 / 上架 / 拣货的来源与目标库位,可还原任意时点的库位
└─ source_type/source_id, operator_id, created_at

stock_snapshots            每日快照(仓储计费依据,生成后不可改;粒度到库存单元,可按 Job 汇总)
├─ snapshot_date, timezone(Australia/Melbourne)
├─ client_id, job_id, asn_line_id, stock_unit_id, warehouse_id
├─ condition, pallet_class, unit_type, pallet_source, location_type
├─ billable_qty            托盘单元 = 1;散箱单元 = 箱数;pickface 库位 = 占用格数(按库位不按托盘)
├─ billable_cbm            散箱单元的体积(客户按 cbm·week 计费时用)
└─ period_start / period_end   所属计费周(周一起算,可配置)
   规则:入库当周计一整周;出库当周计一整周;当天进当天出不计;部分周不按天折算(可配置)

stock_reservations         预留(独立记录,便于自动释放与追溯)
└─ order_id, stock_unit_id, qty, status(active | released | consumed), created_at, released_reason

waves                      波次:一批订单一起释放,生成 pick 类型的 warehouse_tasks
packages                   打包结果(交给 TMS:package_type, weight, L/W/H, carton_label;每个包裹一张箱标 → 出库 label & despatch 费按包裹数计)
stocktakes                 盘点:system_qty, counted_qty, variance, reason
```

## 4.3 主流程与明文规则

```
入库:  Inbound(客户门户或 Coordinator 建 ASN;无预报到货建临时收货单;一批货进两个仓 = 同一 Job 下两张 ASN)
        → 到货 → Receiving 逐行记实收/短溢/损坏
              └─ 实收 ≠ 预报:仍可收,自动生成 Discrepancy 异常 → Coordinator 队列
        → 质检 → Putaway 上架(人工选库位 + 系统校验)→ Available
        └─ 事件 asn.putaway_completed(payload 带托盘数 / 箱数、各托盘 pallet_source、打印箱标数)→ Billing 上架费 + 进库 label 费 + 仓库供应托盘的购买费;卡车散货入库的收货任务记卸货托盘数 → 卸货费;拆柜费只由拆柜任务完成触发(见 VAS)

分配:  OMS 事件 order.confirmed → WMS 预留具体库存单元
        ├─ 成功 → 事件 stock.reserved → OMS 订单进 allocated
        └─ 不足 → 事件 stock.reservation_failed → OMS 走 OMS-7/8(警告 + 拆出可发部分)
        订单取消 / 减量 → 事件 stock.released(自动释放,不留假缺货)

出库:  波次释放 → 拣货任务(按库位排序,逐条确认,扫码校验)
        ├─ 拣不到足量 → Pick Short 异常 → Coordinator:backorder / 部分发 / 取消
        → 复核 → Packing 打包(录类型/重/尺寸,打箱标;需缠膜 / 打带则加 wrap 任务)→ 状态 packed(Ready for Shipment)
        └─ 事件 outbound.packed(payload 逐行带 unit_type、数量、单箱重量、箱标数)→ Billing 订单处理费(加急按客户 cut-off 判定)/ 整托拣货费或分档纸箱拣货费 / 出库 label & despatch 费 → TMS 开始报价
        → Dispatch 发运交接(记装车托盘数 → load 任务 → 装车费)→ 状态 dispatched(已离仓)

退货:  OMS Return Requested → 退货运输(TMS)→ 到仓 → return_receipts 收货
        → 验收 → Available / Quarantine / Damaged → Return Closed
        └─ 事件 return.received(库存增加)、return.inspected(验收完成)→ Billing 的 credit 只在 return.financial_decision 后触发一次
        司机标记退回 ≠ 仓库已收到;只有 return_receipts 验收完成才增加库存

VAS:   拆柜 / 缠膜打带(进库 / 出库)/ 序列号扫描(逐个存 scan_records)/ 人工时(班内 / 班外)/ 废弃物(CBM,最低 1)→ warehouse_tasks 逐项记录
        └─ 事件 task.completed(含 task_type、数量、工时)→ Billing 按 charge code 计费

日常:  盘点 / 移库 / 损坏隔离;每日快照(带托盘类型、托盘来源、pickface 占用)→ Billing 按周出仓储费与托盘租赁费
```

**明文规则(开发时逐条对照):**

1. **库存"状态"分两件事,不混:** `condition`(good / quarantine / damaged)是库存单元的物理状态;**预留是数量不是状态** —— `available_qty = qty_on_hand − qty_reserved`,一个单元可以部分预留,预留明细在 stock_reservations。Picked、Packed、Dispatched 是订单与履约批次的状态,**不进库存**。
2. **上架后才可用:** 收货区的货不可被分配;`putaway_completed` 之前 `qty_on_hand` 不计入可用。
3. **库位校验(基础版):** 库位存在、属于当前仓库、未禁用;容量与规则校验不做。
4. **预留必须可释放:** 每条预留独立记录;订单取消或减量时自动释放并写流水。
5. **Ready 与离仓分开:** `packed` = 可发运,`dispatched` = 已交给司机或承运商;两者各有时间戳。
6. **所有权:** WMS 只写自己的表与事件;订单状态由 OMS 消费事件后推进。
7. **退货先验收再入库:** `return.received` 只在 return_receipts 验收完成后发出。
8. **VAS 必须有任务记录:** 没有 warehouse_tasks 记录的 VAS 不计费。
9. **流水是事实,余额是投影:** `stock_ledger` 是唯一真实来源;`stock_units.qty_*` 是同一事务内维护的性能投影;每次移动在同一事务里写流水并更新余额;`stock:reconcile` 校验两者一致。
10. **预留要加锁:** 预留时对库存单元行加锁(或乐观版本号),防止两张订单同时占用同一批货。
11. **拆托与并托只记流水:** 从托盘拆箱、多箱并托用 `split` / `merge` 两种流水类型记录数量变化,同一次操作的多条流水共用 `movement_group_id` 并记 `from_stock_unit_id` / `to_stock_unit_id`,不建托盘—纸箱层级。
12. **计费数量由 WMS 带出,Billing 不猜:** 每个收费项都有明确的数量来源 —— 卸货 / 上架 / 装车按托盘数,拣货按任务行(整托 vs 纸箱,纸箱带单箱重量),label 按箱标 / 包裹数,序列号按 scan_records 行数,人工时按班内 / 班外小时,废弃物按 CBM;所有阈值(托盘尺寸、800 kg、22 / 45 kg、20 行、22.5 t)取自客户价目表,代码不写死。

## 4.4 屏幕清单

| 屏幕 | 用途 | 对应 PRD 需求 |
|---|---|---|
| 库存查询 | 按客户 / Job / 唛头 / 货物行 / 库位 / 状态查,导出;点进看流水 | **WMS-1 客户库存隔离** — 每个客户的库存严格分开,显示可用与已预留,记录每次移动 |
| 库存流水 | 某库存单元的全部移动,可溯源到单据 | **WMS-1** — "records every movement";同时服务 PLT-8 留痕 |
| ASN 列表 / 新建 | 客户(门户)或 Coordinator 预告到货,选柜型与拆柜方式,填预期明细;无预报到货可建临时收货单 | **WMS-2 入库** — 客户预告到货,仓库按预告收货 |
| ASN Excel 导入 | 上传《需派送货物清单》→ 按货物行生成 asn_lines(唛头、品名、箱数、重量尺寸、收件人保留在行上)→ 行级报错 → 确认;可指定所属 Job 与柜号 | **WMS-2 入库** — "Clients notify expected deliveries";同一份清单以后不再重录 |
| 从 ASN 生成订单 | 上架完成后,按"唛头 + 收件地址 + FBA 引用"把 ASN 货物行分组,一键生成派送订单(同组地址不一致则阻断),已生成的行不可重复生成 | **OMS-1 订单录入** 的第五条路径 — 一份文件只录一次;订单挂同一 Job |
| 收货作业 | 按 ASN 逐行收,记实收/短溢/损坏与原因;托盘单元录实测长宽高重与托盘来源(客户自带 / 仓库木托 / CHEP / LOSCAM),系统按客户价目表阈值建议托盘类型;卡车散货记卸货托盘数;差异自动生成异常进 Coordinator 队列 | **WMS-2** — "records any differences";价目表卸货费 / 托盘租赁 / 仓储分类的数据来源 |
| 上架 | 收货区 → 人工选库位(系统校验),确认后转为可用 | **WMS-2** — "Stock becomes available once put away" |
| 分配与预留 | 订单确认后锁定具体库存单元;预留列表可查、可释放 | **WMS-1** 的预留部分 + **OMS-7/8** — 已预留与可用分开显示 |
| 波次释放 | 按客户/承运商/送达日筛选订单,批量释放 | **WMS-3 出库** — "Confirmed orders create pick lists" |
| 拣货任务 | 按库位排序的任务列表,逐条确认(可扫码校验);拣不到足量生成 Pick Short 异常 | **WMS-3** — 拣货与复核 |
| 打包 | 录包裹类型、重量、长宽高;每个包裹打一张箱标(出库 label & despatch 费按此计);需缠膜 / 打带时加 wrap 任务;生成 packing list(含扫描的序列号) | **WMS-3** + **WMS-10 打包** — 包裹尺寸重量是运费与尾板判定的输入 |
| 发运交接 | packed(可发)→ dispatched(已离仓),记录交接时间与人、装车托盘数(装车费) | **WMS-10 发运** — 货物离开仓库的时点必须被记录 |
| 盘点 | 发起、录实数、看差异、提交调整(必填原因) | **WMS-4 库存核对** — "corrections, always with a reason recorded" |
| 移库 | 库位间/仓库间移动,每次记录 | **WMS-7 移库与多仓** |
| 损坏隔离 | 标记损坏、填原因、传照片,移出可用 | **WMS-8 损坏库存** — "quarantine status with a reason (and photos)" |
| 扫码作业 | 扫码枪与手机摄像头两种输入:收货扫箱标、上架扫库位、拣货校验、盘点 | **WMS-5 条码扫描** — 确认拿对了货,替代纸单;**核心范围** |
| VAS 任务 | 拆柜 / 缠膜打带(进库、出库分 code)/ 序列号扫描(逐个存序列号)/ 人工时(班内、班外分开填)/ 废弃物(CBM)的任务下发与完成确认 | **WMS-2 / WMS-10** 的作业执行部分;真实价目表的 VAS 收费项以此为凭证 |
| 退货收货与验收 | 按原单收退货、验收、判定去向(可用 / 隔离 / 损坏) | **OMS-9 退货** 的仓库侧 — "brings the goods back into the client’s stock (or damaged stock)" |
| 每日快照 | 无界面,定时任务;可查历史快照,按托盘类型、托盘来源、pickface 占用分类 | **WMS-4** — "A daily stock record is kept, which is also the basis for storage charges" |
| 仓库配置 | 营业时间(班内 / 班外)、pickface 库位;托盘分类阈值与加急 cut-off 在客户价目表 / 客户主数据里维护(Billing / MasterData) | 支撑价目表的班内班外人工时、加急派送、托盘分类 |

## 4.5 任务概览

| # | 任务 | 依赖 | 对标与取舍 |
|---|---|---|---|
| B3 | ✅ M2 · 接入 Platform 的 Outbox 发布工具 + 定义 WMS / TMS 事件(不建表;outbox_events 归 Platform) | A0, A31 | CargoWise 一个数据库(以事件替代系统间对账) |
| B1 | ✅ M2 · 库存核心:stock_units(客户 + 货物行 + 包装单元 + 库位)+ ledger + 对账命令 | A0, A27 | CartonCloud 隔离(原样)· Extensiv 流水(原样) |
| B2 | ✅ M2 · 入库:ASN 挂 Job + 可选 containers(基础字段)+ 收货差异 → 异常 + 托盘实测与来源 + 卸货托盘数 + 上架校验 + 无预报临时收货单 | B1 | 柜为 ASN 下可选对象(只取概念,不做柜级生命周期) |
| B2b | ✅ M2 · ASN Excel 导入:真实清单 → 货物行(含收件人字段);与 OMS 共用解析器;导入记录进导入历史 | B2 | CartonCloud 乱格式接入 |
| B2c | 从 ASN 生成订单:上架后按唛头分组调 OMS 的 OrderService 建单;记录 asn_line ↔ order_line 对应;防重复生成 | B2b, A3 | CartonCloud 单据链(入库单 → 出库单) |
| B4a | ✅ M2 · 分配与预留:消费 order.confirmed、写预留、失败回报、取消/减量自动释放 | B1, A3 | Extensiv · CartonCloud 预留独立步骤(原样) |
| B4 | 出库:波次 + 拣货任务(warehouse_tasks.pick + 明细行)+ Pick Short 异常 + 复核打包(打箱标;事件逐行带单箱重量)+ packed/dispatched 交接(记装车托盘数) | B4a | Microlistics 任务化(简化)· Logiwa 波次(只取概念) |
| B10a | 每日快照 job(托盘类型 / 托盘来源 / pickface 占用) | B1 | Extensiv 快照计费(采用,周期改为周) |
| B10b | 盘点 / 移库 / 损坏隔离(带照片) | B1 | CartonCloud 库内管理 |
| B14 | 多仓支持(切换、跨仓移库) | B1 | Extensiv 多仓 |
| B11 | 扫码作业:箱标与库位条码打印;扫码枪输入(所有表单)+ 手机摄像头扫码页;收货/上架/拣货/盘点四处接入 | B2, B4 | CartonCloud 移动优先(原样,核心范围) |
| B12 | warehouse_tasks:VAS 任务(拆柜 / 缠膜打带 / 序列号扫描 + scan_records / 人工时班内班外 / 废弃物 CBM)下发、完成、发 task.completed 事件 | B1 | Microlistics 任务化(简化采用) |
| B13 | 退货收货与验收:return_receipts、去向判定、事件 return.received / return.inspected | B1, A11 | CartonCloud · Extensiv 退货验收 |

## 4.6 每个任务满足哪条需求

**B3 · 接入 Outbox 发布工具 + 定义 WMS / TMS 事件**

`依赖 A0、A31 · 对标:CargoWise 一个数据库`

- 无直接 PRD 条目,是架构任务。**不建事件表** —— `outbox_events` / `consumed_events` 归 Platform(A31 建);B3 只做两件事:在 WMS / TMS 的写事务里调用 Platform 的 Outbox 发布器,以及在 `contracts/events.md` 里定义本模块发出的事件与 payload。保证 **PRD 目标 1**"订单只录一次,流经仓库、配送、计费不重复输入"在两账号并行开发下仍然成立。

**B1 · 库存核心**

`依赖 A0、A27 · 对标:CartonCloud 多货主隔离、Extensiv 流水对账`

- **WMS-1 客户库存隔离** — 每个客户的库存严格分开;显示可用与已预留;记录每一次移动。
- 库存单元粒度 = 客户 + 货物行 + 包装单元 + 库位;同一唛头下不同货物行分开;每个包装单元有系统条码(`label_code`)作为扫码对象;所有单元挂 `job_id`。

**B2 · 入库(ASN + 收货差异 → 异常 + 上架校验 + 无预报临时收货单)**

`依赖 B1 · 对标:柜为 ASN 下可选对象(只取概念)`

- **WMS-2 入库** — 客户(门户)或 Coordinator 预告到货;按预告收货、记录差异、上架到编码库位;上架后才可用。
- 实收 ≠ 预报时仍可收货,但生成 Discrepancy 异常进 Coordinator 队列处理;无预报到货建临时收货单,Coordinator 确认后才可上架。
- 上架为人工选库位 + 系统基础校验(存在 / 属于本仓 / 未禁用)。
- 真实价目表把 20ft/40ft 拆柜列为独立计费项,故柜型与拆柜方式必须是 ASN 的一级字段。
- 柜只记柜号、柜型、拆柜方式、毛重、行数,不做柜级生命周期(2026-09-07)。收货时托盘单元录实测长宽高重与托盘来源(客户自带 / 仓库木托 / CHEP / LOSCAM),系统按客户价目表阈值建议托盘类型;卡车散货入库的收货任务记卸货托盘数(卸货费)。

**B2b · ASN Excel 导入**

`依赖 B2 · 对标:CartonCloud 乱格式接入`

- **WMS-2 入库** — "Clients notify expected deliveries":头程货代在柜到之前把《需派送货物清单》发来,直接导入为 ASN 货物行。按行不按唛头:每行一条 asn_line,唛头、收件人、FBA 引用、货值等字段原样保留在行上,供后续生成订单用。解析器与 OMS 的 A4 共用(同一模板、同一校验),导入记录、失败行与重传由 WMS 自己的导入记录表管理。
- 导入时指定所属 Job(无则新建)与柜号(整柜时);散货导入不填柜号。导入时**预检**同一唛头下的收件地址与 FBA 引用是否一致,不一致标警告,让货代在柜到之前改清单。

**B2c · 从 ASN 生成订单**

`依赖 B2b、A3 · 对标:CartonCloud 单据链`

- **OMS-1 订单录入** 的第五条路径 — 上架完成后,按 **唛头 + 收件地址 + FBA 引用** 把该 ASN 的货物行分组,一键生成派送订单(同一唛头下地址或 FBA 引用不一致时阻断并要求人工确认),订单挂同一 Job,订单行与 asn_line 一一对应并直接指向对应库存单元(预留可立即成功)。已生成过的货物行标记为已生成,不可重复;部分货物行短收时只按实收生成。
- 所有权边界:WMS 不写 orders 表,而是调用 OMS 的 `OrderService::createFromAsn()`;生成结果写回 asn_lines 的 `order_line_id`。

**B4a · 分配与预留**

`依赖 B1、A3 · 对标:Extensiv、CartonCloud 预留独立步骤`

- **WMS-1 客户库存隔离** — "显示可用与已预留":预留为独立记录,消费 `order.confirmed` 锁定具体库存单元,回报 `stock.reserved` 或 `stock.reservation_failed`;订单取消或减量时自动释放并写流水。
- 支撑 **OMS-7 在库校验** 与 **OMS-8 分批发货**:预留失败即触发 OMS 的警告与拆出可发部分。

**B4 · 出库(波次 + 拣货任务 + Pick Short 异常 + 复核打包 + 交接)**

`依赖 B4a · 对标:Microlistics 任务化、Logiwa 波次`

- **WMS-3 出库** — 确认并已预留的订单生成拣货任务;拣货、复核、打包;拣不到足量生成 Pick Short 异常交 Coordinator(backorder / 部分发 / 取消)。
- **WMS-10 打包与发运** — 每个包裹记录类型、重量、长宽高并打箱标;`packed`(Ready for Shipment)与 `dispatched`(已离仓)分开记录。打包完成即发事件供 TMS 报价。
- 打包完成事件逐行带 unit_type、数量、单箱重量与箱标数,供 Billing 区分整托拣 / 分档纸箱拣与出库 label & despatch 费;订单处理费的加急判定按客户 cut-off 配置;发运交接记装车托盘数(装车费)。

**B10a · 每日快照 job**

`依赖 B1 · 对标:Extensiv 快照即计费依据`

- **WMS-4 库存核对** — "保留每日库存记录,并作为仓储费的依据"。按托盘类型分类,供 Billing 按**周**汇总;快照带托盘来源与 pickface 占用格数,供托盘租赁费与 pickface 周费。

**B10b · 盘点 / 移库 / 损坏隔离**

`依赖 B1 · 对标:CartonCloud 库内管理`

- **WMS-4** 盘点与调整必填原因;**WMS-7** 库位与仓库间移动每次记录;**WMS-8** 损坏移入隔离,带原因与照片,排除出可用库存。

**B14 · 多仓支持**

`依赖 B1 · 对标:Extensiv 多仓`

- **WMS-7 多仓** — "supports more than one warehouse from the start";用户可切换当前仓库,权限按仓库限定。

**B12 · warehouse_tasks(VAS 任务)**

`依赖 B1 · 对标:Microlistics 任务化`

- **WMS-2 / WMS-10** 中的拆柜、缠膜打带、序列号扫描、人工时、废弃物等作业以任务记录下发与完成;完成发 `task.completed` 事件(带 task_type、billable_qty / uom、班内 / 班外小时、进库 / 出库来源),Billing 按 charge code 计费。真实价目表的 Devanning、Shrink wrap / Strap(进出库两个 code)、Serial number scanning、Man-hour(班内 / 班外)、Waste disposal 收费项由此获得执行凭证;序列号本身存 `scan_records`,数量自动等于记录数。两个 Label 项(进库箱标、出库 label & despatch)按系统打印的箱标数 / 包裹数自动计费,不需要任务记录(2026-09-07 决定,推翻 09-01 的"Label 不纳入")。**不做**重新码托与 FBA 贴标服务。

**B13 · 退货收货与验收**

`依赖 B1、A11 · 对标:CartonCloud、Extensiv 退货验收`

- **OMS-9 退货**(仓库侧)— 退货到仓后建 return_receipts,验收后判定去向(可用 / 隔离 / 损坏),只有验收完成才增加库存并发 `return.received`、`return.inspected`;credit 由 OMS / Billing 在 Financial Decision 后以 `return.financial_decision` 触发一次,WMS 不发 credit 相关事件。

**B11 · 扫码作业(核心范围)**

`依赖 B2、B4 · 对标:CartonCloud 移动优先`

- **WMS-5 条码扫描** — 扫码确认拿对了货,替代纸单。收货时打印系统箱标、库位打印库位条码(我们没有商品条码)。
- 两种输入并存:扫码枪(键盘输入,收货/上架/拣货/盘点表单原生支持)与手机摄像头扫码页(html5-qrcode)。
- 决策:2026-08-31 确认为核心范围,PRD WMS-5 的"后期功能"字样应移除。

**未纳入:** **WMS-6 批次/效期**(当前客户货物不涉及,字段预留)、**WMS-9 低库存提醒**(删除:3PL 不负责客户补货)。

## 4.7 验收标准

1. 40ft 拆柜 ASN,收货录 5 箱短少与 2 箱破损并填原因,上架后可用库存正确;
2. `stock:reconcile` 输出差异为零;
3. 客户 A 的货绝不会被分配给客户 B 的订单,即使唛头相似;
4. 释放 20 张订单的波次,拣货任务按库位路径排序;
5. 打包录 25kg 包裹后,尺寸重量传给 TMS 并触发尾板判定;
6. 隔离的损坏货不出现在可用库存;
7. 每日快照自动生成,可回查任意一天、按托盘类型分类;
8. 实收少于预报仍能完成收货,同时 Coordinator 队列出现该差异;
9. 订单确认后库存显示已预留;取消订单后预留自动释放,流水有记录;
10. 拣货拣不到足量时生成 Pick Short 异常,Coordinator 选"部分发"后订单进入履约批次;
11. 打包完成为 packed,交接后为 dispatched,两者时间戳不同;
12. 收货用扫码枪扫箱标、拣货用手机扫码页校验,两种方式都能完成同一作业;
13. 同一唛头下的三行货物入库后是三个库存单元,可分别预留与拣货;
14. 散货卡车入库的 ASN 没有 containers 记录,整柜入库的有,且拆柜费只对后者产生;
15. 完成一项"人工时"VAS 任务后,charges 出现对应费用行且可溯源到该任务;
16. 司机标记退回后库存不变;仓库退货验收完成后库存才增加;
17. 把真实《需派送货物清单》导入为 ASN,上架后一键生成订单:订单数等于唛头数,每张订单的行与库存单元一一对应,同一份文件不再需要在 OMS 重新导入;
18. 对已生成订单的 ASN 再点一次"生成订单",不产生重复订单。
19. 收货录一托 1200 × 1200 × 1600 mm、600 kg,系统建议 oversize_high;录 1200 × 1200 × 1400、900 kg,建议 overweight 并进 POA 待报价;改客户价目表的阈值后,新收货按新阈值建议,已收货的分类不变;
20. 托盘来源为 warehouse_plain / CHEP 的托盘单元在周快照里带来源,Billing 据此产生托盘租赁费;客户自带托盘不产生;
21. pickface 库位有货的周,快照按占用格数计 pickface 周费,不按托盘数;
22. 拣一整托与拣三箱(10 / 30 / 50 kg)后,outbound.packed 事件逐行带单箱重量,Billing 产生一条整托拣货费与三档各一条纸箱拣货费;
23. 卡车散货 ASN 收货记 6 托、上架 6 托、发运装车 6 托,三个事件各带托盘数,产生卸货 / 上架 / 装车三条费用;
24. labour 任务填班内 2 h、班外 1 h,产生两条不同 code 的人工时费;序列号扫描任务扫 5 个序列号后 scan_records 5 行、扫描费 qty = 5,序列号印在 packing list 上;进库、出库各一次缠膜任务产生两条不同 code 的费用;废弃物 0.5 CBM 按最低 1 CBM 计。

## 4.8 已定的口径(原待确认项)

- ~~隔离中的损坏货是否照收仓储费?~~ 已定(v4.4):照收,独立 charge code 单列;报废移出后停止。
- ~~托盘类型由谁判定?~~ 已定(v4.4 系统建议 + 人工确认;阈值于 2026-09-07 改按客户价目表):收货录入托盘长宽高重后,系统按**客户价目表**里的阈值建议 pallet_class,收货人确认或改选(改选记原因)。默认阈值取自 Edward 价目表:standard = 1200 × 1200 × ≤ 1400 mm 且 < 800 kg;oversize_high = 高 ≤ 1800 mm;oversize_wide = 一边 ≤ 2400 mm;≥ 800 kg = overweight(POA);高 > 1800 或长 > 2400 等超出所有档位 → POA 待报价。阈值随价目表版本,可改不写死。

## 4.9 行业建议(本版不采纳,备查)

| 建议 | 来自 | 优势 |
|---|---|---|
| 收货照片存档 | CartonCloud · Extensiv | 货损纠纷时是证据;可复用 POD 上传组件 |
| 超尺寸/超重自动标记 | Microlistics | 自动标"需叉车/需两人",影响拣货安排与收费 |
| 上架库位建议 | Logiwa | 按货主与货量建议库位,减少找位时间(规模够大才划算) |

---

# 5. TMS 运输模块(账号 B)

## 5.1 能力研究与取舍

### 5.1.1 报价与方案选择(transport option framework)—— MachShip / Shippit

**优势:** 两家的核心机制是**报价先于执行**:输入收发地址、包裹重量尺寸、服务等级,系统一次返回多个方案(不同承运商 × 服务),并标记推荐 / 最便宜 / 最快;发货人选定后才进入预订。自有车队与第三方承运商在同一个框架里比较,没有"两套逻辑"。

**取舍:** **采用**,并以此重构整个 TMS:Shipment → Quote → Selection → Booking → Tracking → POD → Exception → Billing。一期直接对接 **Transdirect(TD)** 与 **EIZ** 两家澳洲聚合平台(2026-08-31 确认;华人卖家常用,一次对接覆盖多家承运商);MachShip 保留为能力参照。**舍弃** 自建承运商直连。

### 5.1.2 派单与 run sheet —— TransVirtual

**优势:** TransVirtual 是为承运商写的 TMS,建模对象是"一辆车的一天":一趟 run 包含有序停靠点,每点有要送的货与回单。从仓库视角出发的系统只建模"一件包裹的旅程",司机拿到的是零散任务而不是路线。

**取舍:** **采用**(用于自有车队方案)。**舍弃** 转运网络、分拨中心、代理商结算。

### 5.1.3 司机端与电子签收 —— TransVirtual / CartonCloud

**优势:** **签收即闭环**:司机在手机上签名拍照,系统同一时刻更新订单状态、给客户发带 POD 的邮件、生成运费、记录成本。纸质回单隔天补录会让这四件事都延迟一天。

**取舍:** **采用**:自有车队用手机网页表单(签名 + 拍照 + 提交,不做原生 App);第三方承运商的 POD 以 API 回传的 **POD 文件**为准(2026-08-31 确认)。

### 5.1.4 预订、面单与追踪自动化 —— MachShip(经 Transdirect / EIZ 落地)

**优势:** 选定方案后一步完成预订,平台返回 tracking number 与 label / waybill;之后轨迹由平台 API 自动回传,不需要人工查询或录入。承运商对接这条"维护跑步机"由聚合平台承担。

**取舍:** **采用**:`CarrierAdapter` 接口,一期实现 Transdirect、EIZ 两个适配器 + Manual 兜底;第三方面单由平台返回,自有车队用我们自己的标签(B6)。

### 5.1.5 分包成本与客户价 —— MachShip / CargoWise

**优势:** CargoWise 把收入与成本挂在同一票货上,毛利实时可见。聚合平台的报价本身就是**成本**,客户价由成本按规则加成得出,内部成本对客户不可见。

**取舍:** **采用**(2026-08-31 确认):第三方运费 = 报价成本 × 每客户可配置 markup;自有车队用后台固定费率(如 pallet $75 + GST),内部成本人工填。两种定价模式在 Billing 的 `rate_items` 并存(§6.3)。

### 5.1.6 运费对账 —— MachShip

**优势:** 承运商账单与系统预期逐票比对、差异标红,付款前追问。

**取舍:** **采用**;预期成本直接来自报价快照,比对不再依赖人工估算。一期可先导出比对。

### 5.1.7 明确不采用的

| 不采用 | 谁 | 理由 |
|---|---|---|
| 自建承运商直连 | MachShip 本身 | 判据三:聚合平台已覆盖,直连是无底洞 |
| 路线优化 / 装载优化 | Descartes · Logiwa | 停靠点少,人工排线足够(PRD Not Included) |
| 快递面单与 B2C 小包 | Shippit · CartonCloud(B2C 侧) | 只做 B2B |
| 转运网络 / 分拨中心 | TransVirtual | 单城配送 + 分包,无转运层 |
| 实时 GPS 追踪 | Descartes · Transvirtual | POD 时间戳与坐标已够用 |
| 原生司机 App | TransVirtual | 手机网页表单已满足签收闭环 |

## 5.2 数据模型(owner:账号 B)

```
carrier_services           承运商的运输属性(B 拥有;承运商主数据 carriers 归 A 的 MasterData)
├─ carrier_id              引用 A 的 carriers(code、name、ABN、联系)
├─ source                  own_fleet | transdirect | eiz | manual(对应 CarrierAdapter 实现)
└─ services                支持的服务等级与默认时效

shipments                  一次发运(订单 → shipment → package)
├─ shipment_no, job_id, order_id / fulfilment_id
├─ shipment_type           outbound(派送 / 纯运输)| return(退货运输)
├─ status                  outbound:quoting → quoted → quote_confirmed → booked → dispatched → in_transit → delivered → failed | booking_cancelled
│                          return:  return_requested → return_in_transit → arrived_warehouse(之后交 WMS return_receipts)
├─ selected_quote_id       选定方案
├─ carrier_id, service_level
├─ booking_ref, tracking_number, waybill_pdf     预订后由平台返回(自派为自有标签)
├─ tailgate_required       来自 OMS-13
└─ consignment_note_pdf

transport_quotes           报价方案(一次报价多条)
├─ shipment_id, carrier_id, source, service_level
├─ cost_cents              成本(第三方为平台报价;自派为后台费率)
├─ customer_price_cents    客户价(第三方 = 成本 × markup;自派 = 固定费率)
├─ eta_days
├─ is_recommended, is_cheapest, is_fastest
├─ quote_stage             preliminary(下单时按申报尺寸重量)| final(打包实测后)
├─ status                  quoted | selected | expired | requoted | booking_cancelled
├─ selected_by             client | coordinator | system
└─ quoted_at, expires_at   过期后必须重新报价;重新报价保留旧记录

delivery_runs              自有车队班次(车 + 有序停靠点)
├─ run_no, run_date, driver_id, vehicle, status
└─ stops:  shipment_id, seq, eta, arrived_at, status

pods                       签收凭证
├─ shipment_id, delivered_at, recipient_name
├─ signature_image, photos[]            自派:司机网页采集
├─ pod_file                             第三方:平台 API 回传文件
└─ failure_reason

tracking_events            轨迹:平台 API 自动回传 / 自派人工
carrier_costs              expected_cost(= 报价成本), actual_cost, variance, note
carrier_invoices           承运商账单 + 逐票比对行
```

## 5.3 主流程

```
OMS 确认订单时:Preliminary Estimate(按 declared_packages 申报的尺寸重量出初步方案与估价,写入客户报价单)
纯运输订单(pickup_deliver):不经过 WMS,永远没有 outbound.packed —— 以 `order.confirmed` 为触发,按 declared_packages 直接出最终方案;司机取货时可复核,差异走 delivery.extra_charge
WMS 事件 outbound.packed(带包裹实测重量/尺寸/件数)
  → Quote:   Final Carrier Quote —— TransportOptionService 汇总方案(与初步估价差异超过容差 → 要求客户或 Coordinator 重新确认)
              ├─ own_fleet:后台固定费率 → 客户价
              ├─ Transdirect API:报价 → 成本 × markup → 客户价
              └─ EIZ API:      报价 → 成本 × markup → 客户价
              → 标记 Recommended / Cheapest / Fastest
  → Selection: 系统推荐;客户(门户)可确认推荐或改选;Coordinator 可代选
             └─ 事件 shipment.quote_confirmed → Billing 生成运费(客户价)+ 尾板费(如 tailgate_required;**唯一产生点**)→ per_job 客户开出服务发票(monthly 客户进未开票池);**预订不等收款**
  → Booking: 第三方 → 平台 API 预订 → 返回 tracking no + waybill
             自派   → 编入 delivery_run → 打自有 label
             (预订前检查:该 shipment 的服务发票已收款,且客户无 active financial hold;否则不得预订)
             └─ 事件 shipment.booked → 记 carrier_cost(成本)与预订信息;**不产生费用**
  → Tracking: 第三方由 API 自动回传;自派由司机页推进
  → POD:     自派:司机网页签名 + 拍照;第三方:平台回传 POD 文件
             └─ 事件 delivery.pod_captured → 订单已送达 + 客户邮件(附 POD)+ 运费可结算
  → Exception: 失败 → 异常列表 → 客服处理 → 重派;额外费用发事件 delivery.extra_charge → Billing 决定收费
  → Return:    退货运输 = shipment.type = return(拒收回仓或客户主动退货),状态 return_in_transit → arrived_warehouse;到仓后交 WMS return_receipts 验收
```

## 5.4 屏幕清单

| 屏幕 | 用途 | 对应 PRD 需求 |
|---|---|---|
| 待报价清单 | 已打包(packed)的 shipment;纯运输订单在确认后即进入,按申报包裹报价 | **TMS-1 配送计划** — 打包好的订单进入运输安排 |
| 报价与方案选择 | 一次显示自派 + Transdirect + EIZ 的方案,标 Recommended / Cheapest / Fastest;客户价与(内部可见的)成本分列 | **TMS 服务等级(PRD 子项)** + **OMS-3 运输报价与服务选择** — 客户可确认推荐或改选 |
| 预订 | 选定方案后一键预订,回显 tracking no 与 waybill | **TMS-3 配送状态** — "Where a contracted carrier issues tracking numbers, these are stored" |
| 班次编排(自派) | 有序停靠点、指派司机/车辆 | **TMS-1** — "assigned to a driver" |
| consignment note / 自有 label | 自派的清单与标签;第三方用平台 waybill | **TMS-1**、**TMS 标签(PRD 子项)** |
| 司机网页(自派) | 今日停靠点、签名 + 拍照、失败原因;显著提示尾板 | **TMS-2 司机任务与签收** |
| 配送状态与异常 | 状态自动回写(第三方 API / 自派司机页);失败与延误进跟进列表 | **TMS-3 配送状态** |
| 客户通知(后台) | 送达后自动邮件附 POD(签名图或平台 POD 文件);失败改为告警 | **TMS-4 配送通知** |
| 成本与毛利 | 每票:报价成本 vs 客户价;自派成本人工填 | **TMS-5 运费成本** — "the margin on every job is visible" |
| 承运商对账 | 承运商账单 vs 报价成本逐票比对,差异标红 | **TMS-6 承运商账单对账** |

## 5.5 任务概览

| # | 任务 | 依赖 | 对标与取舍 |
|---|---|---|---|
| B5 | shipment 结构 + 状态机 + transport_quotes 表 + consignment note | B4 | MachShip 报价先于执行(采用) |
| B5e | ✅ 2026-09-07 · **Vendor API Discovery(Go / No-Go 门槛)**:Transdirect 有公开 API 文档(tracking / POD / label);EIZ 的外部 Partner API 能力未经公开证实。逐项验证报价字段、预订、tracking(webhook 或轮询)、POD 文件回传、waybill 格式、沙箱;产出接口契约。任一平台 No-Go 时该平台不进一期,Manual fallback 保证流程不阻塞 | — | MachShip(验证前不承诺能力) |
| B5c | TransportOptionService + `CarrierAdapter`:Transdirect、EIZ 适配器(报价 / 预订 / 追踪 / POD 文件)+ Manual 兜底 + own_fleet 固定费率方案;无可用方案进 Manual Transport Exception | B5e, B5, A5 | MachShip 经 TD/EIZ 落地(采用);舍自建直连 |
| B5d | 方案选择:Recommended / Cheapest / Fastest 标记规则;客户门户确认或改选;Coordinator 代选 | B5c | Shippit 服务等级驱动(采用) |
| B5b | 班次编排(自派:有序停靠点、指派司机) | B5 | TransVirtual run = 车 + 停靠点 |
| B6 | 自有 label 打印(自派用;第三方用平台 waybill) | B5 | TransVirtual 标签 |
| B7 | 司机网页表单 + POD(签名 / 拍照 / 失败原因) | B5b | TransVirtual · CartonCloud 签收即闭环 |
| B8 | 状态回写(API 自动 + 司机页)+ 异常列表 + POD 邮件 + `delivery.extra_charge` 事件 | B5c, B7 | TransVirtual 闭环 · MachShip 追踪 |
| B9a | 成本记录:第三方自动取报价成本,自派人工填;每票毛利 | B5c | CargoWise Job 利润 |
| B9b | 承运商账单对账(导入 + 与报价成本比对) | B9a | MachShip 运费对账 |

## 5.6 每个任务满足哪条需求

**B5 · shipment 结构 + 状态机 + transport_quotes + consignment note**

`依赖 B4 · 对标:MachShip 报价先于执行`

- **TMS-1 配送计划** — 每次配送有 consignment note 列明车上装了什么。
- **TMS 服务等级(PRD 子项)** — 订单 → shipment → package → 承运商 → 服务等级;方案表记录每个候选的成本、客户价、时效。

**B5e · Vendor API Discovery**

`对标:MachShip(能力验证先于承诺)`

- **Go / No-Go 门槛**,不是普通开发任务:在开发适配器之前验证两家平台的真实能力 —— 报价接口字段、预订返回内容、tracking 是 webhook 还是需轮询、POD 文件能否回传、waybill 格式、沙箱是否可用。Transdirect 有公开 API 文档并说明账户可访问 tracking、POD、label;EIZ 公开资料只证明其自身界面有报价 / 打单 / 追踪,外部 Partner API 未经证实。产出 `contracts/carriers.md`;验证前不对 POD 回传、webhook、面单格式做承诺;任一平台 No-Go 则该平台不进一期,Manual 实现保证流程不阻塞。

**B5c · TransportOptionService + Transdirect / EIZ 适配器**

`依赖 B5e、B5、A5 · 对标:MachShip 多承运商编排(经 TD/EIZ 落地)`

- **TMS-1 配送计划** — "assigned to a driver or a contracted carrier":第三方方案来自两家平台的实时报价,自派方案来自后台固定费率。
- **TMS-3 配送状态** — 预订后平台返回 tracking number 与 waybill;轨迹由 API 自动回传。
- **TMS-5 运费成本** — 平台报价即成本,自动写入 `carrier_costs.expected_cost`。
- 2026-08-31 决策:两家平台进一期范围;API 账号与沙箱为前置条件(§5.8)。

**B5d · 方案选择**

`依赖 B5c · 对标:Shippit 服务等级驱动价格与时效`

- **OMS-3 运输报价与服务选择** — 下单时可见客户价;系统推荐,客户可确认或改选其他方案。
- 标记规则(已定,v4.4,MachShip / Shippit 做法):Cheapest = 客户价最低;Fastest = eta 最短;**Recommended = 满足要求送达日与约束的方案中客户价最低**;可配一个全局偏好 `own_fleet_preference_percent`(默认 0):自派方案客户价高出最低价不超过该百分比时优先推荐自派。不做更复杂的评分。
- 两阶段:下单时的 Preliminary Estimate 与打包后的 Final Carrier Quote;最终报价与初步估价差异超过容差(默认 10%,可配置)时,方案回到"待确认",由客户(门户)或 Coordinator 重新确认后才能预订。

**B5b · 班次编排(自派)**

`依赖 B5 · 对标:TransVirtual run sheet`

- **TMS-1 配送计划** — 自派 shipment 编入班次,停靠点有序,指派司机与车辆。

**B6 · 自有 label 打印**

`依赖 B5 · 对标:TransVirtual 标签`

- **TMS 标签(PRD 子项)** — 自派包裹:收件人与地址、shipment ID、条码、承运商;第三方包裹直接打印平台返回的 waybill。

**B7 · 司机网页表单 + POD**

`依赖 B5b · 对标:TransVirtual 司机端、CartonCloud POD`

- **TMS-2 司机任务与签收** — 司机手机看停靠点,交货时签名与拍照,凭证存到订单;失败选原因。手机网页,不做原生 App。
- 尾板标记(OMS-13)在任务页显著显示。

**B8 · 状态回写 + 异常列表 + POD 邮件 + 额外费用事件**

`依赖 B5c、B7 · 对标:TransVirtual 闭环、MachShip 追踪`

- **TMS-3 配送状态** — 第三方由 API 自动更新,自派由司机页推进;失败或延误进跟进列表;tracking number 员工与客户可见。
- **TMS-4 配送通知** — 送达后自动邮件附 POD(自派为签名图,第三方为平台 POD 文件);失败改为告警。
- 等候、二次派送等额外费用由 TMS 发 `delivery.extra_charge` 事件,收不收、收多少由 Billing 按价目表决定。

**B9a · 成本记录与毛利**

`依赖 B5c · 对标:CargoWise Job 利润`

- **TMS-5 运费成本** — 第三方成本自动取自报价,自派成本人工填;与客户价并列,每单毛利可见;成本对客户不可见。

**B9b · 承运商账单对账**

`依赖 B9a · 对标:MachShip 运费对账`

- **TMS-6 承运商账单对账** — 承运商账单与报价成本逐票比对,差异标出以便付款前追问。

## 5.7 验收标准

1. 打包完成的 shipment 向所有合格的 Transport Source 请求报价,展示全部成功返回且满足约束的方案并正确标记 Recommended / Cheapest / Fastest;某地址无任何可用方案时进入 Manual Transport Exception,不阻塞流程;
2. 客户在门户确认推荐方案或改选,确认后 charges 出现运费(客户价),预订后 carrier_costs 出现成本(平台报价),客户端看不到成本;预订不受收款状态限制;仅 Finance 人工置财务锁时预订按钮不可用,放行后恢复;
3. 第三方预订后系统持有 tracking number 与 waybill,轨迹无人工干预自动更新;
4. 自派 shipment 编入班次,司机在手机网页签收后:订单已送达、客户收到附 POD 的邮件、运费可结算三件事同时发生;
5. 第三方送达后,平台 POD 文件挂到订单并随邮件发给客户;
6. 配送失败进异常列表可重派;等候费经 `delivery.extra_charge` 进入 Billing;
7. 订单详情页显示"收 − 付 = 毛利"。

## 5.8 已定的口径(原待确认项)

- **Transdirect 与 EIZ 的 API 账号、沙箱、费率 —— 开工前行动项(不是设计问题)**:由团队在 Block 0 之前申请;B5e 以拿到的沙箱为准做 Go / No-Go;截止 M4 仍未拿到的平台不进一期,`CarrierAdapter` 的 Manual 实现保证流程不阻塞(§8.9)。
- ~~markup 默认值与配置粒度~~ 已定(v4.4,MachShip 做法):客户级默认加成(`clients.default_markup_percent`)+ 价目表里可按 承运商 × 服务等级 覆盖;默认值由财务在导入价目表时填,不在系统里假设。
- ~~Recommended 的规则~~ 已定(v4.4):满足送达日的最低客户价;自派偏好用一个百分比配置,默认 0。

## 5.9 行业建议(本版不采纳,备查)

| 建议 | 来自 | 优势 |
|---|---|---|
| **配送时间窗 / 预约送货** | 澳洲承运商通行 | FBA 仓与多数商业收货点必须预约;没有预约号司机会被拒收。**你们有 FBA 派送,实用性最高** |
| 住宅地址附加费 | 澳洲承运商通行 | residential surcharge 是该收的钱;可与 OMS-14 地址簿的地址类型共用 |
| 危险品标记 | CargoWise · Descartes | 电池、化妆品等 DG 货不能派给无资质承运商 |
| 二次派送自动计费 | MachShip · TransVirtual | failed / re-delivery 是最常漏收的费用;现已可经 `delivery.extra_charge` 落账,只差价目表配置 |

---

# 6. Billing 计费模块(账号 A)

## 6.1 能力研究与取舍

### 6.1.1 作业自动生成费用 —— CartonCloud

**优势:** 见 §1.3。这是整个系统最值得学的一条,也是我们与竞品最直接对位的能力。

**取舍:** **原样采用**。事件驱动:作业写 `domain_events`,计费引擎消费生成 charges,每条挂源单据可溯源。

### 6.1.2 费率颗粒度 —— Extensiv

**优势:** Extensiv(原 3PL Central)的 3PL billing 被认为是行业最完整的之一,原因是它把费率的每一个变化维度都做成了字段:按活动、按单位、按阶梯、按最低收费、按附加费、按手工费。3PL 的真实费率卡千奇百怪,只要有一个维度没做成字段,就得写死在代码里,下一个客户就得改代码。

**取舍:** **原样采用"每个维度都是字段"的原则**,并用真实价目表验证 —— 真实价目表有九种纳入系统的计费单位(含标签)、纸箱三档重量、托盘五类、CBM 最低收费、POA 面议项,**这些全部必须是 `rate_items` 的字段,不能写死。**

### 6.1.3 澳洲 GST 与本地会计生态 —— CartonCloud

**优势:** 它是澳洲公司,GST 是原生的:价目表不含税、账单层加税、直连 Xero/MYOB/QuickBooks。对澳洲 3PL 来说这不是加分项而是及格线 —— 记账员用的就是 Xero 或 MYOB。

**取舍:** **采用 GST 处理方式,但按 charge code 而非发票总额**:费率不含税,每个 charge code 带 `tax_treatment`(gst_10 / gst_free / out_of_scope),发票按行汇总 GST —— 现在全是澳洲国内费用,但不把"总额加 10%"写死。**舍弃会计对接**(FIN-4 已从 PRD 删除),保留导出接口。

### 6.1.4 Job 利润 —— CargoWise

**优势:** 见 §5.1.5。财务侧的意义是:收入与成本挂在同一条记录上,不需要月底把两个系统的数据对起来才知道赚没赚。

**取舍:** **采用**。charges(收)与 carrier_costs(付)挂同一订单,订单页与客户报表显示毛利。

### 6.1.5 账单核对 —— Extensiv

**优势:** 账单一旦发给客户就很难改,错账伤信任。Extensiv 在出账与发出之间加了审核环节,手工加费必须写原因。

**取舍:** **简化采用**。不设独立审核工序:填单人在草稿页核对费用行后直接发出,draft → 发出;发出后不可改,只能开 credit;手工加费需原因并进审批中心(PLT-7)。

### 6.1.6 Charge Code 目录与费率版本 —— CargoWise / Extensiv

**优势:** CargoWise 的费用不是枚举而是 **Charge Code 目录**:每个 code 有类别、默认单位、触发事件、对客描述、税务处理,费率卡只是给 code 定价;一个作业事件可以同时产生多个 code(基础运费 + 燃油 + 尾板 + 偏远)。Extensiv 与 CartonCloud 的费率卡都带生效日期,且历史费用保存当时的费率快照,改价不会污染旧账。

**取舍:** **原样采用**。新增 `charge_codes` 表,`rate_items` 引用 code;计费链固定为 Operational Event → Charge Code → Rate Item → Charge → Invoice Line。费率项不可原地覆盖(改价生成新版本),charges 保存费率与计算快照;找不到费率进 Missing Rate Exception,不得算成 $0。

### 6.1.7 发票按 Job 结算 —— CargoWise

**优势:** CargoWise 按 Job 开票:一票货完成即结算,货代收到的是"这个柜的账",而不是月底一张混合了几十个柜的对账单。

**取舍:** **采用;开票时机与账期按客户配置,资格控制到费用项而不是整个 Job**(2026-09-07 改:取消"所有客户预付、先款后发"):

- **顺序(方案 B,2026-09-01 确认):拣货打包 → 最终报价确认 → 按实测一次开票 → 预订 / 发运。** 仓库先把货整理好(拣货、打包、实测尺寸重量),客户确认最终报价时产生运费与尾板费(事件 `shipment.quote_confirmed`),此时操作费(拣货、订单处理)已因打包产生,发票**引用已存在的 charges** 一次开准,不需要预收与二次结算(`invoice_mode = per_job`);`monthly` 客户的费用先进未开票池,月底汇总成一张。
- **账期按客户配置**(`clients.payment_terms` = prepaid | eom | net_N,N 可填):发票到期日据此计算 —— prepaid = 开票即到期;eom = 发票所在月月底后 N 天(默认 30);net_N = 开票日 + N 天。逾期发票在列表标红并进对账单。**系统不因未收款阻止预订或发运**(2026-09-07 决定);财务锁只由 Finance / Coordinator 人工置。
- **仓储费按周结算**(周末才知道存了几托):`per_job` 客户每周开一张仓储发票,`monthly` 客户的周仓储费并入月账单;到期与逾期按客户账期。托盘租赁费与 pickface 周费随仓储费同周结算。长期 Job 会进入多张周发票或多张月账单。
- **送达后只处理差异**:等候费、二次派送、承运商复称高于报价 → 补充发票;低于 → credit note。POD 是结案前提,不是开票前提。
- **Billing Hold = 人工发运锁**:只有 Finance / Coordinator 人工置锁才卡预订 / 发运,不卡开票;收款状态与锁无关。开票只看费用是否已产生并批准。
- 发票可按单个 Job 开,也可月底跨 Job 合并(`clients.invoice_mode` = per_job | monthly;prepaid 客户强制 per_job),出账时可手动多选 Job;月度 Statement 延后。

### 6.1.8 明确不采用的

| 不采用 | 谁 | 理由 |
|---|---|---|
| 内置总账 / 会计账套 | CargoWise · Magaya | 判据二:它们服务把软件当唯一系统的公司;我们的客户有自己的会计软件(PRD 已明确不做) |
| 多币种与汇率 | CargoWise · Descartes | 只有 AUD 一个币种 |
| 客户账户 / 押金 / 信用额度 / 往来账 | Extensiv · CargoWise | 2026-09-01 决定不做;财务锁由人工置锁,收款只记发票级别,不建客户账户与往来账 |
| 自动催收 / 信用评分 | Extensiv | 超出 PRD 范围;逾期只标红,个别欠款客户由 Finance 人工置财务锁 |

## 6.2 真实价目表带来的硬约束

来自 **Storage_rate_27022026.xlsx**(客户价目表,AUD,不含 GST):

1. **仓储费按"托盘 · 周"计费,不是按天。** 每日快照 → 按周汇总出账。托盘分标准 / 超宽 / 超高 / 超重(POA)/ pickface,另有托盘租赁(plain / CHEP / LOSCAM)。
2. **九种纳入系统的计费单位:** per container(20/40ft)、per pallet、per pallet·week、per carton(<22kg / 22–44.99kg / ≥45kg 三档)、per order、per label、per scan、per CBM(最低 1 CBM)、per man-hour(班内/班外)。价目表全部 34 行导入(2026-09-07 决定,推翻 09-01 的"Label 不纳入";Cartage 两行作为 TMS 运费项)。
3. **POA(面议)项**必须能标记为"需人工报价",不能算出错误金额。
4. **最低收费与重量分档**必须是费率项的一等属性。
5. **价目表里所有数字都是费率项参数:** 单价、分档边界(22 / 45 kg)、托盘尺寸与重量阈值(1200 × 1200 × 1400 / 1800 / 2400 mm、800 kg)、拆柜行数上限(20)、集装箱吨位门槛(22.5 t)、最低收费、托盘租赁 / 购买单价 —— 全部存在 rate_items / rate_cards 上(`threshold_json`),代码不写死,每个客户可不同;Edward 价目表作为标准表的默认值 Seed 进去。
6. **账期按客户:** prepaid | eom | net_N;invoice_mode = per_job | monthly。发票只算到期与逾期,不卡预订与发运。

## 6.3 数据模型(owner:账号 A)

```
charge_codes               费用目录(学 CargoWise;费率卡只给 code 定价)
├─ code                    如 WH-DEVAN-20-PLT、WH-PUTAWAY-PLT、WH-PICK-CTN-LT22、WH-STORAGE-PLT-WK、
│                          VAS-SCAN、VAS-LABOUR-HR、TR-DELIVERY-BASE、TR-FUEL、TR-TAILGATE、TR-FAILED、TR-REDELIVERY
├─ category                warehouse | vas | transport | storage | other
├─ default_uom             container_20 | container_40 | pallet | pallet_week | carton | carton_week | cbm_week | order | scan | cbm | man_hour | delivery
├─ customer_description / internal_description   charge code 只描述"这是什么费"
├─ tax_treatment           gst_10 | gst_free | out_of_scope
└─ active

charge_rules               触发规则:决定"什么时候、什么条件下产生哪条费用"(学 Extensiv 确定性匹配)
├─ trigger_event           task.completed(devanning) | asn.putaway_completed | outbound.packed | shipment.quote_confirmed
│                          | delivery.extra_charge | snapshot.weekly | return.financial_decision
├─ charge_code_id
├─ condition               如 tailgate_required = true;zone = remote;task_type = scanning;container.size = 40
├─ quantity_source         从事件的哪个字段取数量(cartons / pallets / billable_qty / weeks / labels / scans / hours_business / hours_after_hours / cbm / pickface_slots)
├─ rate_match_priority     费率匹配顺序:客户专属表 → 客户绑定的标准表 → Missing Rate
├─ effective_from/to
└─ idempotency_key_template   如 {source_activity_id}:{charge_code}:{activity_version}

rate_cards                 客户价目表(带版本)
├─ client_id, name, currency(AUD)
├─ version, effective_from/to, status(draft | active | superseded)
└─ is_standard             标准价目表;通过 clients.standard_rate_card_id 绑定后作为 Fallback。**目前默认新建客户即绑定标准表**(可人工解绑);已解绑或标准表也缺 code 时进 Missing Rate,不产生 $0 费用

rate_items                 费率项(不可原地覆盖;改价生成新版本)
├─ rate_card_id, charge_code_id
├─ pallet_class            standard | oversize_wide | oversize_high | overweight | pickface
├─ threshold_json          该费率项的判定参数(托盘 L/W/H/重量阈值、拆柜行数上限、集装箱吨位门槛、加急 cut-off 等),随版本可改不写死
├─ weight_band_min/max     纸箱重量分档
├─ zone                    运费分区(州 + 都会/偏远)
├─ pricing_mode            fixed | cost_plus(第三方运费:成本 × markup)
├─ carrier_id, service_level(可空)   cost_plus 行可按 承运商 × 服务等级 覆盖客户默认加成
├─ markup_percent          未按承运商覆盖时取 clients.default_markup_percent
├─ rate_cents, min_charge_cents
├─ is_poa                  面议:不自动计价,生成待报价项
└─ notes

charges                    费用行(计费引擎产出)
├─ job_id, client_id, charge_date
├─ charge_code_id, rate_card_id, rate_card_version, rate_item_id
├─ uom, qty, rate_snapshot_cents, amount_cents
├─ calculation_snapshot_json   当时的分档 / 分区 / markup / 最低收费判定
├─ tax_treatment           从 charge code 复制,发票按行汇总
├─ status                  pending | needs_review | approved | invoiced | disputed | reversed
├─ source_type/source_id   morph 到 asn / container / task / shipment / snapshot / order
├─ source_activity_id, activity_version   业务级防重复键:unique(source_activity_id, charge_code_id, activity_version)
├─ reversal_of_charge_id   冲销 / 调整指向原费用行;取消或重做**不删费用,只生成冲销**
├─ is_manual, manual_reason, created_by
└─ invoice_line_id

(成本侧数据由 TMS 的 carrier_costs 提供,见 §5.2;Billing 只读)

invoices                   发票(可覆盖 1..n 个 Job;一个 Job 也可进多张发票,如每周仓储费)
├─ invoice_no, client_id, period_from/to(合并模式时)
├─ invoice_type            service(最终报价确认后,含操作费与运费)| storage(周仓储)| supplementary(送达后差异)| monthly(月底汇总,内按 Job 分组)
├─ bill_to_name, bill_to_address, bill_to_abn   开票时的客户快照,不引用可变的主数据
├─ status                  draft → issued → paid / part_paid
├─ paid_at, paid_amount    收款记录(发票级别;不影响预订与发运)
├─ due_at, is_overdue      到期日按 clients.payment_terms 计算(prepaid 即时 / eom / net_N);逾期只标红,不锁发运
├─ subtotal_cents, gst_cents, total_cents
└─ pdf_path                 正式 Tax Invoice 字段(ABN、GST 明细等)由财务按 ATO 要求复核

invoice_jobs               发票 ↔ Job 多对多
└─ invoice_id, job_id
invoice_lines              charge_id, job_id, charge_code, description, qty, amount_cents, tax_treatment, gst_cents(按 Job 分组展示)

credit_notes               贷项通知(发出后的发票只能以此冲减)
└─ invoice_id, job_id, reason, lines, amount_cents, gst_cents, status, approved_by

payments                   invoice_id, amount_cents, paid_at, method
customer_quotes            客户报价单(FIN-6 与 OMS-3 共用):job_id, client_id, order_id, stage(preliminary | final), valid_until, status
customer_quote_lines       charge_code, qty, uom, amount_cents, assumptions(拆柜 / 上架 / 预计仓储 / 拣货 / 运输 / 尾板附加 / GST);运输行引用 transport_quote_id
(exceptions 表归 Platform;Billing 经 ExceptionService 写入 Missing Rate / Billing Hold 等异常,不建表)
```

## 6.4 计费触发点

计费链:**Operational Event → Charge Rule(条件匹配,可多条)→ Charge Code → Rate Item(带版本)→ Charge(带快照、状态、防重复键)→ Invoice Line**。一个事件可命中多条规则,例如 `shipment.quote_confirmed` 命中 TR-DELIVERY-BASE,若 tailgate_required 再命中 TR-TAILGATE,若 zone = remote 再命中 TR-REMOTE —— 不是每票都产生全部费用。找不到匹配费率 → Missing Rate Exception,不自动算 $0;作业取消或重做 → 生成冲销,不删费用。

| 事件(来自 WMS/TMS) | 生成的费用 | 费率单位 |
|---|---|---|
| `asn.putaway_completed` | 上架费(payload 带托盘数)+ 进库 label 费(按打印箱标数)+ 仓库供应托盘的购买费(按 pallet_source)(**拆柜费不在此产生**) | per pallet / per label |
| `stock.daily_snapshot_taken` → `snapshot.weekly` | 每周出仓储费(按托盘类型)+ 托盘租赁费(按 pallet_source)+ pickface 周费(按占用格数):每个计费单元每周最多一次,幂等键 = 单元 + 周 + code | 托盘:**per pallet·week**;pickface:per pickface·week;散箱:per carton·week 或 per cbm·week(按客户价目表) |
| `outbound.packed` | 订单处理费(加急按客户 cut-off 判定)+ 拣货费(整托 per pallet / 纸箱按单箱重量分档)+ 出库 label & despatch 费(按箱标数) | per order / per pallet / per carton(分档)/ per label |
| `shipment.quote_confirmed` | 运费(客户价)+ 尾板费(如判定)—— **唯一产生点**,在打包实测之后、预订之前 | 自派:固定费率(如 pallet $75);第三方:报价成本 × markup |
| `shipment.booked` | 不产生费用;记 carrier_cost(成本)与预订信息 | — |
| `delivery.extra_charge` | 等候、二次派送、失败派送等附加费 | 按价目表附加费项;Billing 决定是否收费 |
| `task.completed`(warehouse_tasks) | 拆柜费(**唯一触发点**,按柜型 × 拆柜方式;mixed 或行数超上限 → 待报价)、卸货费、装车费、缠膜打带(进 / 出库 code)、序列号扫描费、人工时(班内 / 班外)、废弃物(最低 1 CBM) | per container / pallet / scan / man_hour / cbm(取 billable_qty / billable_uom) |
| `return.financial_decision` | credit note(退货冲减)—— **唯一触发点**,在验收完成、财务决定后 | 按原费用行冲减 |
| `delivery.pod_captured` | 确认运费可结算;记 carrier_cost | — |
| 人工 | 加班人工费、等候费、POA 报价 | per man_hour / 手工 |

## 6.5 屏幕清单

| 屏幕 | 用途 | 对应 PRD 需求 |
|---|---|---|
| 价目表列表 / 编辑 | 每客户一份,含生效日期;可复制上一版改价 | **FIN-1 客户价目表** — 每客户有约定价目表(仓储、操作、配送);有生效日期使历史费用不变 |
| Charge Code 目录 | 维护费用目录:code、类别、默认单位、对客描述、税务处理 | **FIN-1 / FIN-2** 的基础;计费是配置不是代码 |
| Charge Rule 规则 | 维护触发规则:事件、条件、数量来源、费率匹配顺序、生效期 | **FIN-2** — "什么时候产生哪条费用"是配置 |
| 费率项编辑 | 按 charge code 配置,支持分档、分区、最低收费、cost_plus、POA;改价生成新版本 | **FIN-1** + **FIN-5 高级计费规则** — 体积重取大、燃油附加、最低收费、等候/失败/二次派送等附加费 |
| 费用列表 | 按 Job / 客户 / 期间 / code / 状态筛;每条点进源单据与费率快照 | **FIN-2 自动计费(核心)** — 作业按价目表自动生成费用行;每条显示来自哪张单据 |
| 未开票池 | 已完成作业但尚未进入发票的费用(按 Job 汇总) | **FIN-2 / FIN-3** — 防止已完成的作业漏开票 |
| 手工加费 | 一次性费用,必填原因,进审批 | **FIN-5** — "Staff can also add one-off manual charges with a reason" |
| 待报价项 | POA 费率产生的待人工报价清单 | **FIN-1** — 真实价目表含 POA 项,不能自动算错 |
| 账单生成 / 发出 | 服务发票:最终报价确认后按已产生的操作费与运费生成;仓储:按周生成;差异:送达后补充发票或 credit note;合并模式可多选 Job。草稿页核对后直接发出(draft → 发出,不设独立审核);发出后只能开 credit note;monthly 客户:未开票池月底一键汇总;到期日按客户账期,逾期标红,不卡预订 | **FIN-3 对账单与发票** + **PLT-7 审批**(仅 credit note)— 开票资格按费用项判断 |
| 客户账期与开票设置 | payment_terms(prepaid / eom / net_N)、invoice_mode(per_job / monthly)、加急 cut-off、标准表绑定 —— 在客户主数据维护(MasterData) | **FIN-3** 的客户级参数 |
| Credit Note | 对已发出发票的冲减,需审批 | **FIN-3** 的"credit"落地 |
| 账单 PDF | 含 GST 的正式账单,每行可溯源 | **FIN-7 税率设置** — 税率配置一次自动应用 |
| 收款记录 | 全额 / 部分收款,客户未结余额(按发票汇总) | **FIN-8 收款记录** — 平台不成为会计账套 |
| 报价 | 一次性运输任务按价目表报价 | **FIN-6 报价** — 客户确认后转为订单 |
| Job 利润 | 每个 Job:charges(收)− carrier_costs(付) | **TMS-5** 的财务侧呈现;CargoWise Job profitability |

## 6.6 任务概览

| # | 任务 | 依赖 | 对标与取舍 |
|---|---|---|---|
| A24 | ✅ c4 · charge_codes 目录 + charge_rules 触发规则 + 真实价目表映射为 code(Seeder) | A2 | CargoWise Charge Code · Extensiv 确定性匹配 |
| A5 | ✅ c4 · 价目表(版本化)+ 费率项(引用 code;九种 UOM / 分档 / 最低 / POA / cost_plus / threshold_json 阈值参数;不可原地覆盖)+ Fallback 表 + 真实价目表 Seeder(全部 34 行,含 Label) | A24 | Extensiv 每个维度都是字段(原样)· 版本与快照 |
| A6a | ✅ c4 · 计费引擎:消费事件 → 匹配 charge_rules → charges(带快照、状态、防重复键);取消 / 重做生成冲销;Missing Rate Exception | A5, A24, B3 | CartonCloud 作业即计费 · 逆向冲销 |
| A6b | ✅ c4 · 周仓储费 job(快照 × 托盘类型 × 周) | A6a, B10a | Extensiv 快照计费(周期改为周) |
| A8a | ✅ c4 · 发票生成:服务发票(最终报价确认后,引用已存在 charges)、周仓储发票、送达后补充发票 / credit note、跨 Job 合并;客户快照;按行 GST;收款登记(付款后放行预订);PDF | A6a | CargoWise 按 Job 开票 · Transdirect 打包后报价、付款、再预订 |
| A8b | ✅ c4 · 手工加费 + 待报价项 + 未开票池 + credit_notes | A8a | Extensiv 账单控制项 |
| A18 | ✅ c4 · 报价(FIN-6,与 OMS A7b 共用计算) | A5 | CargoWise 报价 · Magaya |
| A10 | ✅ c4 · 收款记录 + 未结余额(按发票) | A8a | 轻量,不做总账 |

## 6.7 每个任务满足哪条需求

**A24 · charge_codes 目录**

`依赖 A2 · 对标:CargoWise Charge Code 目录`

- **FIN-1 / FIN-2** 的基础 — 费用目录独立于价目表:code、类别、默认单位、触发事件、对客描述、税务处理。真实价目表的每一行映射为一个 code(如 WH-DEVAN-20-PLT、WH-PICK-CTN-LT22、WH-STORAGE-PLT-WK)。

**A5 · 价目表(版本化)+ 费率项 + 真实价目表 Seeder**

`依赖 A24 · 对标:Extensiv 费率颗粒度、CartonCloud 客户价目表`

- **FIN-1 客户价目表** — 每客户一份(仓储、操作、配送),带生效日期使历史费用不变。
- **FIN-5 高级计费规则** — 体积重取大、燃油附加(百分比)、每单最低收费、等候/失败/二次派送附加费,均为费率项属性而非代码。
- 真实价目表决定字段:九种 UOM、纸箱三档、托盘五类、CBM 最低 1、POA 面议、阈值参数(托盘尺寸 / 重量、拆柜行数、吨位、cut-off);全部 34 行导入(Cartage 两行作为 TMS 运费项),阈值随价目表版本可改。
- 运费两种定价模式并存(2026-08-31 确认):自派 `fixed` 固定费率;第三方 `cost_plus` = 平台报价成本 × 每客户 markup,内部成本对客户不可见。
- 费率项不可原地覆盖,改价生成新版本;客户专属表缺 code 时用客户绑定的标准价目表兜底。**目前先默认绑定标准价目表**:新建客户时 `clients.standard_rate_card_id` 默认指向标准表,可人工解绑;标准表也缺该 code、或客户已解绑时 → Missing Rate Exception,不产生 $0 费用。

**A6a · 计费引擎(事件 → charges)**

`依赖 A5、B3 · 对标:CartonCloud 作业即计费`

- **FIN-2 自动计费(核心)** — 仓库与配送作业按价目表自动生成费用行,每条显示来自哪张单据。触发点见 §6.4;一个事件可产生多个 code;charges 保存费率快照与计算快照,状态从 pending 起。

**A6b · 周仓储费 job**

`依赖 A6a、B10a · 对标:Extensiv 快照计费`

- **FIN-1 / FIN-2** — 真实价目表是**托盘·周**计费。**公式(防止算成 7 倍):** 每个可计费包装单元在同一计费周**最多产生一次**费用;该周任一天的每日快照里出现即计一次(与"入库当周计一整周、出库当周计一整周"一致),不是对每日数量求和。幂等键 = `stock_unit_id(或托盘身份)+ billing_week + charge_code`,重跑 job 不重复计费。按托盘类型分别计价。
- **散箱仓储(已定,2026-09-01,CartonCloud / Extensiv 做法):** 不做"N 箱折一托"的换算。散箱(unit_type = carton、不在托盘上)按客户价目表里配置的散货仓储单位计费 —— `carton_week`(每箱每周)或 `cbm_week`(每立方米每周,CBM 取自货物行申报 / 实测);客户价目表两者都没配 → Missing Rate Exception,不静默套用。快照的 billable_qty 对托盘单元 = 1,对散箱单元 = 箱数与 CBM 各存一份。

**A8a · 账单生成 + GST + PDF**

`依赖 A6a · 对标:CartonCloud 一键出账`

- **FIN-3 对账单与发票** — 开票资格按费用项判断:`per_job` 客户在打包实测、客户确认最终报价后,按已存在的操作费与运费 charges 一次开出服务发票;`monthly` 客户的费用进未开票池,月底汇总成一张(内按 Job 分组);仓储费按周产生,per_job 客户周开票、monthly 客户并入月账单;到期日按 `clients.payment_terms`(prepaid / eom / net_N)计算,逾期标红,**不阻止预订与发运**;送达后差异走补充发票或 credit note。发票保存开票时的客户名称、地址、ABN 快照;每行可溯源到源单据。POD 缺失 / 成本未确认的运费行可暂不开票(由费用项状态控制)。
- **FIN-7 税率设置** — 费率不含税;发票按每行 charge code 的 tax_treatment 汇总 GST。

**A8b · 手工加费 + 待报价项**

`依赖 A8a · 对标:Extensiv 账单控制项`

- **FIN-5** 手工一次性费用需填原因;**PLT-7** credit note、价目表变更需第二人批准(账单发出不走审批)。
- 未开票池:已完成作业但未进发票的费用按 Job 汇总,防漏开;credit_notes 是发出后发票的唯一冲减方式。

**A18 · 报价**

`依赖 A5 · 对标:CargoWise 报价、Magaya`

- **FIN-6 报价** — 一次性运输任务按客户价目表报价,确认后转为订单;与 OMS 的 A7b 共用同一套计算。

**A10 · 收款记录**

`依赖 A8a`

- **FIN-8 收款记录** — 标记全额或部分收款,客户未结余额按发票汇总始终可见,平台不成为会计账套。
- **OMS-11 财务锁** 由 Finance / Coordinator 人工置锁与放行,系统不按押金或信用额度自动触发(2026-09-01 决定)。Job 利润由 Job 下的 charges 与 carrier_costs 汇总得出,不另设任务。

**未纳入本期:** **FIN-4 会计对接**(已从 PRD 删除,保留导出接口)。

## 6.8 验收标准

1. 导入真实价目表后,九种计费单位都能配置且算对,全部 34 行导入(Cartage 两行落在运费项);POA 项不自动计价而进待报价清单;托盘尺寸 / 重量阈值、22 / 45 kg 分档、20 行上限、22.5 t 门槛都能在价目表上改,改后新费用按新参数算、旧费用不变;
2. 40ft 拆柜 ASN:拆柜任务完成产生一条拆柜费,上架完成产生上架费,两者各只一条,金额与价目表一致;
3. 订单打包后产生订单处理费与分档拣货费;预订车辆后产生运费(自派固定价 / 第三方成本 × markup)与尾板费(仅一条);
4. 周仓储费按托盘类型正确出账,可回查依据的每日快照;
5. 改价目表后历史账单金额不变;
6. 账单 PDF 含 GST,每行可点回源单据;
7. 部分收款后客户未结余额正确;
8. 一个 `shipment.quote_confirmed` 事件同时产生基础运费、燃油、尾板三条费用行,各自对应不同 charge code;`shipment.booked` 不产生费用;
9. 某客户价目表缺少某个 code 时,用其绑定的标准表(默认绑定)兜底;标准表也缺该 code、或该客户已解绑时进 Missing Rate Exception,不产生 $0 费用;先打包、撤销、再打包只产生一条有效打包费与一条冲销;
10. 改价后旧费用行的费率快照不变;
11. 整柜客户的一个 Job 完成后可单独生成一张发票;尾程客户月末将多个已完成的 Job 合并成一张发票,发票内按 Job 分组;
12. per_job 客户:打包实测后客户确认最终报价,系统按已存在的操作费与运费一次生成服务发票;monthly 客户:同样的作业只进未开票池,月底一键汇总成一张,内按 Job 分组;三种账期(prepaid / eom 30 / net 14)各开一张发票,到期日分别为开票当日、次月月底、开票日 + 14 天,过期后列表标红;未收款的发票不影响其 shipment 预订;Finance 人工置锁后该客户新 shipment 不得预订,放行后恢复;存货第 3 周的 per_job Job 已有三张周仓储发票;送达后承运商复称高出报价时生成补充发票;
13. 托盘来源为 CHEP 的 2 托存 3 周,产生 3 条托盘租赁费(qty 2);仓库供应的木托在上架时产生一次托盘购买费;客户自带托盘两者都不产生。

## 6.9 已定的口径(原待确认项)

- ~~散箱如何折算托盘计仓储费?~~ 已定(v4.4):按客户价目表的 carton·week 或 cbm·week 计,未配置进 Missing Rate,不做折托换算。
- ~~退货产生的 credit 怎么落账?~~ 已定:只在 `return.financial_decision` 后以 credit note 触发一次(v4.1)。
- ~~隔离中的损坏货是否照收仓储费?~~ 已定(v4.4):照收,单列。

## 6.10 行业建议(本版不采纳,备查)

| 建议 | 来自 | 优势 |
|---|---|---|
| 燃油附加费按月维护 | 澳洲承运商通行 | 做成"基础价 × 当月燃油百分比",每月改一个数字全部生效 |
| 失败/二次派送自动计费 | MachShip · TransVirtual | 最常漏收的费用,司机记原因即自动落账 |
| 客户账户与信用额度 | Extensiv | 押金余额、信用额度、账期放在客户账户上,超限自动置财务锁;当前决定不做,财务锁人工置 |
| 对账单争议标记 | CargoWise Neo | 客户在门户对某条费用打问号,财务收到通知处理,避免整张账单被拖着不付 |

---

# 7. 端到端验收脚本(全项目)

1. 管理员登录,展示八角色与客户数据隔离;
2. 柜到之前,把真实《需派送货物清单》导入为 40ft 拆柜 ASN 的货物行(含 FBA 行);
3. 收货(录差异)→ 上架 → 库存与流水可查 → 一键从 ASN 按唛头生成派送订单,同一份文件不再重录;
4. 订单确认 → 关联在库批次 → 波次 → 拣货任务 → 打包录实测尺寸重量 → 最终报价(与初步估价对比)→ 客户确认 → 服务发票生成 → 财务登记收款;
5. 预订 shipment(25kg 件自动勾尾板,尾板费在报价确认时已产生一次)→ 打 label → 编入班次 → 司机手机签收;另一张订单属于 monthly 账期客户,费用只进未开票池,预订不等发票与收款;Finance 对第三个客户人工置锁后其 shipment 不能预订,放行后恢复;
6. 客户自动收到附 POD 的邮件;
7. charges 逐条可溯源:拆柜费、上架费、订单处理费、分档拣货费、运费、尾板费、周仓储费;
8. 客户门户登录下载含 GST 的服务发票与本周仓储发票 PDF,并自查订单与库存;
9. 打开该柜的 Job 工作台:全部订单、发货进度、单据、费用、成本与毛利在一页;
10. 整柜客户的费用按 Job 单独开票;尾程客户把三个 Job 的已批准费用合并成一张发票;送达后承运商复称高出报价,生成补充发票;人为制造一次事件消费失败,重试后费用仍正确生成;
11. 展示异常中心、审计日志与审批记录。

---

# 8. 技术栈、仓库结构与协作方式

> 本节原为独立的 DEVELOPMENT_PLAN,已按 v3.9 的数据模型与任务编号更新并并入。不含工期与产能。

## 8.1 技术栈

生产环境将是一台**虚拟服务器(购买暂缓,2026-09-07 决定先全部本地开发,见 `COLLAB_PLAN.md` §2)**。所有选型按"典型虚拟主机约束"设计:**无常驻进程、有 cron、MySQL、SMTP 可用**,本地开发同样遵守,到时零改造。

| 项 | 选择 | 理由 |
|---|---|---|
| 语言 / 框架 | **PHP 8.2 + Laravel 12** | 虚拟主机兼容性最好,零运维,生态完整 |
| 数据库 | **MySQL 8**(本地开发同样用 MySQL,不用 SQLite) | 与生产一致,避免方言差异;行锁与事务是库存预留与 Outbox 的前提 |
| 前端 | **Blade 服务端渲染,无构建步骤** | 简洁、快、不做 UI;禁止 Vue / React / 打包器 |
| CSS | **Pico.css(classless,CDN)+ 一个 ≤100 行的 app.css** | 表格表单自动有样式 |
| JS | 原生 JS 按页内联;仅两个 CDN 库:`html5-qrcode`(手机扫码)、签名 canvas(POD) | 无 npm、无 build;扫码枪走键盘输入,表单原生支持 |
| 定时任务 | Laravel Scheduler + cron `schedule:run` | 每日库存快照、周仓储计费、报价过期、报表邮件 |
| 队列 | `QUEUE_CONNECTION=database` + cron `queue:work --stop-when-empty` | Outbox 投递、邮件、PDF、TD/EIZ 调用重试 |
| PDF | `barryvdh/laravel-dompdf` | 发票、收货回执、拣货单、consignment note、自有 label |
| 条码 | `picqer/php-barcode-generator`(箱标 / 库位码打印)+ `html5-qrcode`(手机扫描) | 纯 PHP + 纯浏览器 |
| 权限 | `spatie/laravel-permission` | 八个角色 + 全局 client scope |
| 审计 | `spatie/laravel-activitylog` | PLT-8 |
| 语言 | UI 文案**全部走 lang 文件(zh 为主)**,禁止硬编码中文进 Blade | 以后加英文只补 `lang/en` |
| 金额 | 整数分,AUD | 铁律第 7 条 |

**明确不做:** 前后端分离、WebSocket、Redis、Docker 生产部署、原生 App。

## 8.2 模块归属与表所有权

> **2026-09-07 起,席位分工以 `COLLAB_PLAN.md` v1.1 为准**(C = Claude Max:Platform / MasterData / **Warehouse** / Billing;X1 = Codex:Orders / Portal / Reports;X2 = Codex:Transport;开发顺序 WMS / TMS 先行)。下文的"账号 A / B"是旧的两账号方案,表清单仍有效,owner 按上述席位映射。

**切分原则:按数据所有权切,不按页面切。** 下表是**唯一权威的表所有权矩阵**:每张表只有一个 owner 账号写 migration 和 model;各模块章节里出现的同名表以本表为准。跨模块只通过 `outbox_events` 与公开 Application Service 通信。

| | 账号 A(业务前段) | 账号 B(业务后段) |
|---|---|---|
| 模块目录 | `Platform`、`MasterData`、`Orders`、`Billing`、`Portal` | `Warehouse`、`Transport`、`Reports` |
| 拥有的表 | users / roles / permissions;clients、client_addresses、suppliers、**carriers(主数据:code、name、ABN、联系)**;**jobs、exceptions(含 holds)、documents、outbox_events、consumed_events(平台共享表,只能经 Service 写)**;orders、order_lines、order_events、order_imports、declared_packages、fulfilments、fulfilment_lines、customer_quotes、customer_quote_lines;charge_codes、charge_rules、rate_cards、rate_items、charges、invoices、invoice_jobs、invoice_lines、credit_notes、payments | warehouses、locations;asns、asn_lines、containers;stock_units、stock_ledger、stock_snapshots、stock_reservations;warehouse_tasks、warehouse_task_lines、waves、packages、stocktakes、return_receipts、return_receipt_lines;**carrier_services(承运商的运输属性)**、shipments、transport_quotes、delivery_runs(含 stops)、pods、tracking_events、carrier_costs、carrier_invoices |
| 提供的 Service | `JobService`、`ExceptionService`、`DocumentService`、`OrderService`(含 `createFromAsn`)、`RateService` | `StockService`(`onHand(client, asnLineId)`、reserve / release)、`TransportOptionService`、`CarrierAdapter` |

平台共享表(jobs、exceptions、documents、outbox_events)归 A,但 **B 通过 Platform 提供的 Service 写入**,与"禁止跨模块写他人业务表"不冲突。仓库与库位的主数据页面在 Warehouse 模块(表归 B),MasterData 模块只管客户、供应商、承运商。

## 8.3 仓库目录与分区

大版块合并能成立的前提是**目录级隔离**:两个账号在磁盘上几乎不碰同一个文件。模块化单体布局,每个模块自带 routes / views / migrations / seeders / lang,通过各自的 ServiceProvider 注册。

```text
erp/
├── CLAUDE.md                        [冻结区] 改动需双方确认(模板见 §8.7)
├── ERP_PLAN.md                      [共同] 本文件;任务表勾选各改各的行
├── MERGELOG.md                      [合并负责人]
├── contracts/                       [冻结区] 见 §8.4
├── data/                            [共同] Storage_rate.xlsx(客户价目表)、需派送货物清单.xlsx(脱敏样例)(Seeder 与导入模板的依据)
├── composer.json / composer.lock    [冻结区] 加包先登记 contracts/dependencies.md
├── config/, bootstrap/              [冻结区] Block 0 定稿
├── routes/web.php                   [冻结区] 只 require 各模块 routes.php
├── resources/views/layouts/         [冻结区] app.blade.php + nav.blade.php(nav 按模块 include)
│   └── nav/                         每模块一个 include:orders.blade.php(A)、warehouse.blade.php(B)…
├── app/
│   ├── Support/                     [冻结区] Money、事件基类、Outbox 发布器、client scope 中间件
│   └── Modules/
│       ├── Platform/                [A] 认证、角色、租户 scope、Jobs、Exceptions、Documents、Outbox、全局搜索、审批、审计、集成监控
│       ├── MasterData/              [A] clients、client_addresses、suppliers、carriers
│       ├── Orders/                  [A] 订单、四维状态、holds、履约批次、导入、客户报价单、退货申请
│       ├── Billing/                 [A] charge_codes / rules、价目表、计费引擎、周仓储、发票、credit note、收款
│       ├── Portal/                  [A] 客户门户入口(复用 Orders / Warehouse / Billing 页面的客户视图)
│       ├── Warehouse/               [B] 仓库库位、ASN / Container、库存、预留、任务、波次、打包、盘点、快照、退货验收、扫码页
│       ├── Transport/               [B] shipment、报价方案、CarrierAdapter(Transdirect / EIZ / Manual)、班次、司机页、POD、成本、对账
│       └── Reports/                 [B] 报表与定时邮件
├── database/
│   ├── migrations/                  [冻结区] 仅 Block 0 框架表(users 等)
│   └── seeders/DatabaseSeeder.php   [共同] 只含 call 列表(各加各的行)
├── lang/zh/                         按模块分文件:orders.php[A]、billing.php[A]、warehouse.php[B]、transport.php[B]…
├── tests/Feature/<模块名>/          跟随模块 owner
└── deploy/DEPLOY.md                 [B] 服务器就绪后补写
```

每个模块内部:`<Module>ServiceProvider.php`、`Http/Controllers/`、`Models/`、`Services/`(对外只暴露 contracts 登记的接口)、`Events/`、`routes.php`、`views/`(前缀 `<module>::`)、`migrations/`、`seeders/`。

**铁律:只能改自己 owner 的目录 + 自己的 lang / tests / nav include。冻结区任何改动 = contract change,需双方在合并检查点确认。**

## 8.4 契约文件(Block 0 必须先写出来)

全文引用的契约文件目前只有名字。**它们的内容全部已在本文里,A0 任务的第一步就是把下面这些抽成文件**;两个账号并行开发不打架靠的就是它们。

| 文件 | 必须包含 | 内容来源 |
|---|---|---|
| `contracts/enums.md` | 订单运营状态 / 履约状态 / 开票状态 / hold 类型;Job 三线状态;ASN、container、shipment、transport_quote、warehouse_task、charge、invoice、return 状态;UOM;pallet_class;unit_type;condition;service_level;pallet_source;location type;task_type;billable_uom;payment_terms(prepaid | eom | net_N);invoice_mode(per_job | monthly);八个角色名 | §1.6、§4.2、§5.2、§6.3、§3.3、§3.4 |
| `contracts/events.md` | 事件名 + payload 字段 + 发布方 + 消费方:`order.confirmed`、`order.cancelled`、`order.reduced`、`stock.reserved`、`stock.reservation_failed`、`stock.released`、`asn.putaway_completed`、`task.completed`、`outbound.packed`、`shipment.quote_confirmed`、`shipment.booked`、`delivery.pod_captured`、`delivery.failed`、`delivery.extra_charge`、`stock.daily_snapshot_taken`、`snapshot.weekly`、`return.requested`、`return.received`、`return.inspected`、`return.financial_decision`;Outbox / Inbox 规则 | §0.2 铁律 4、§4.3、§5.3、§6.4、§3.4 |
| `contracts/db-schema.md` | §8.2 所有权表的活版本 + 每张表的列(从各模块数据模型块抄) | §4.2、§5.2、§6.3、§3.3、§1.6 |
| `contracts/services.md` | 跨模块接口签名:`StockService::onHand(client, asnLineId)` / `reserve` / `release`;`OrderService::createFromAsn(asnId, groupingKey)`;`TransportOptionService::quote(shipment, stage)`;`RateService::price(...)`;`JobService::create / summarize`;`ExceptionService::raise / resolve`;`DocumentService::attach`;各自的 Fake 实现约定 | §4.6、§5.6、§6.7、§3.7 |
| `contracts/routes.md` | URL 前缀:`/admin` `/orders` `/billing` `/portal` `/jobs`(A);`/warehouse` `/transport` `/driver` `/reports`(B) | §8.3 |
| `contracts/carriers.md` | B5e 的产出:Transdirect 与 EIZ 各自实际支持的报价字段、预订返回、tracking 方式、POD 文件、waybill 格式、沙箱;No-Go 项 | §5.6 B5e |
| `contracts/dependencies.md` | composer 加包登记(包名、用途、哪个模块要) | — |
| `contracts/charge-codes.md` | 真实价目表逐行映射到 charge code(全部 34 行,含 Label;threshold_json 阈值参数一并登记),含 UOM、分档、POA | §6.2、§6.3 |

## 8.5 协作:版块分支与检查点合并

> **已被 `COLLAB_PLAN.md` §4 取代**(三席位、WMS / TMS 先行的 M0–M6 顺序)。下文保留作原理说明。

**不做每日合并。** 每个账号在自己的**版块分支**上连续开发,一个大版块做完、测试全绿,才在**检查点**合入 `main`。检查点是**顺序**不是日期。

| 检查点 | 合入内容 | 解锁谁 |
|---|---|---|
| **M0** | Block 0:骨架 + §8.4 全部契约文件 + 冻结区定稿(A0 + B3,两账号结对完成) | 一切;此后冻结区生效 |
| **M1** | `block/A1-platform-masterdata`:A1、A2、A27(Job 主线)、A31(Outbox / 集成监控骨架) | B 需要用户、客户、Job、事件基础 |
| **M2** | `block/B1-stock-inbound`:B1、B2、B2b、B4a | A 的库存校验换真 StockService |
| **M3** | `block/A2-oms`:A3、A4、A7、A7b、A11b、A13、A14–A17 | B 出库需要订单;B2c 需要 `OrderService::createFromAsn` |
| **M4** | `block/B2-outbound-transport`:B2c、B4、B5、B5e(Go/No-Go 结论)、B5c、B5d、B5b、B6、B7 | A 计费引擎有真实事件流 |
| **M5** | `block/A3-billing-portal`:A24、A5、A6a、A6b、A8a、A8b、A18、A10、A9-p、A19、A20、A28–A30;`block/B3-ops-reports`:B8、B9a、B9b、B10a、B10b、B11、B12、B13、B14、A21、A22、A23 | 集成收口 |
| — | 只留 `fix/*` 短分支 | 联调与演示(§7) |

规则:版块分支命名 `block/<账号><序号>-<slug>`;版块内直接提交到版块分支,**每个会话结束必须 push**(电脑随时换,本地不留东西);每个检查点合并后**双方立刻 `git rebase main`**;检查点合并做成 PR,由 Integrator agent(§8.8)执行,CI(pint + phpunit)必须绿;迁移已合入 main 后永远不改;对方版块未合并前,依赖方按 `contracts/services.md` 写 Fake 实现先行开发。

## 8.6 会话仪式(轮换电脑)

**核心假设:本地电脑随时会换,所以本地不可有任何不可再生的东西。一切进 repo,秘密进团队密码库。**

```bash
git clone <repo> || git fetch --all --prune
git checkout block/<自己的当前版块> && git pull --rebase
composer install
cp .env.example .env && php artisan key:generate    # .env 值取自团队密码库
php artisan migrate:fresh --seed                    # 本地库随时可重建(含真实价目表与清单样例)
php artisan test                                     # 绿了再开工
# 读 CLAUDE.md → 本文件对应任务块与 contracts/ → 开写
```

**收工:** `php artisan test` 绿 → `./vendor/bin/pint` → commit + **push 版块分支** → 在本文件任务表打勾。

迁移防冲突:迁移文件名 Laravel 时间戳排序,**已合并进 main 的迁移永远不改**,要改结构就新增迁移;Seeder 按模块分文件,`DatabaseSeeder` 只做 call 列表。

## 8.7 CLAUDE.md 模板(仓库根,英文)

```markdown
# Project: Logistics ERP (Laravel 12 + Blade SSR + MySQL; virtual-server target: cron only, no daemons)

## Read first
- ERP_PLAN.md is the single plan. Read §0 (rules, object hierarchy), §8 (stack, ownership, collaboration), then ONLY the module section for your task (§4 WMS, §5 TMS, §6 Billing, §2 Platform, §3 OMS). Skip the benchmark-research paragraphs (“优势 / 取舍”) — they explain why, not what to build.
- contracts/ is law. Enums, events, table ownership, service signatures, routes must match it verbatim. If a task needs a contract change, stop and request it at a checkpoint.

## Hard rules (from ERP_PLAN §0.2)
- Job is the business backbone: every business record carries job_id. Job = one independent client commission; ASN is the inbound master doc; Container is an optional child of ASN (0..n) with basic fields only (no, size, unpack mode, gross weight, line count) — no container lifecycle.
- Account A owns app/Modules/{Platform,MasterData,Orders,Billing,Portal}. Account B owns app/Modules/{Warehouse,Transport,Reports}. NEVER edit modules, migrations, or lang files you don't own. Shared platform tables (jobs, exceptions, documents, outbox_events) are written only through JobService / ExceptionService / DocumentService / the Outbox publisher.
- Cross-module state changes go through outbox_events written in the SAME DB transaction as the business write; consumers are idempotent via consumed_events. Sync reads go through the public services in contracts/services.md. B never writes charges; A never writes stock_ledger.
- No SKU / product master. Inventory = client + asn_line + packaging unit + location. Marks are for grouping and search only.
- Billing: charge_rules decide when a charge arises; charge_codes describe what it is; charges keep rate snapshots and a business-level unique key; cancel/redo creates reversals, never deletes. Order flow (option B): pick → pack (measured dims) → final quote confirmed → invoice from existing charges → booking/dispatch. Invoicing per client: invoice_mode per_job (invoice from existing charges after final quote) or monthly (charges accumulate in the unbilled pool, one invoice at month end grouped by Job); payment_terms prepaid | eom | net_N only set the due date. Unpaid or overdue invoices NEVER block booking or dispatch — only a manual financial hold placed by Finance does. Every number in the rate card (rates, 22/45 kg bands, pallet-size/weight thresholds, 20-line devanning cap, 22.5 t container cap, cut-off) is a rate-item parameter, never hardcoded. Storage bills per pallet PER WEEK, in arrears. Devanning fee only from the devanning task; transport + tailgate fees only at shipment.quote_confirmed; shipment.booked creates no charge. Return credit only at return.financial_decision.
- Server-rendered Blade only. No npm, no build step, no SPA. Pico.css + minimal app.css. Scan-gun input in every warehouse form; phone camera scanning page via html5-qrcode.
- All UI strings via lang/zh. No hardcoded Chinese in Blade. Money: integer cents, AUD; GST per charge code tax_treatment, summed per invoice line.
- Cost and margin fields must never be queried or serialized for client-role users (server side, not CSS).
- Every task: migration + page + Feature test + lang entries + acceptance items from ERP_PLAN. Run `php artisan test` and pint before pushing. Work on your block branch; push at the end of EVERY session; merges to main only at checkpoints M0–M5 via the integrator agent.
```

## 8.8 Integrator sub-agent(检查点合并)

`.claude/agents/integrator.md`,只在检查点 M0–M5(及之后的 fix 合并)运行:

```markdown
---
name: integrator
description: Checkpoint merge of a block branch into main with full test run. Use at checkpoints M0–M5 only.
tools: Bash, Read, Edit, Grep, Glob
---
You are the integration agent. Given a block branch to merge:
1. `git fetch --all`; checkout main; merge the block branch.
2. Run `composer install`, `php artisan migrate:fresh --seed`, `php artisan test`, `./vendor/bin/pint --test`.
3. Conflict rules:
   - contracts/** or any frozen-zone conflict → STOP, report to humans, never auto-resolve.
   - Files outside the block owner's module directories changed → OWNERSHIP VIOLATION: list them, STOP.
   - DatabaseSeeder / nav include / lang: keep both sides' lines.
   - composer.json: verify additions are registered in contracts/dependencies.md; if not, STOP.
4. If tests fail, identify the failing module; if it's not the merged block's, the block broke a contract — report with the failing test names; never push red main.
5. Push main, tag the checkpoint (M1…M5), append a summary to MERGELOG.md: block merged, tables added, events added, contract changes, test count.
6. Remind both accounts to rebase their active block branches onto the new main.
```

## 8.9 风险与降级

| 风险 | 降级方案 |
|---|---|
| Transdirect 或 EIZ 在 B5e 判 No-Go | 该平台不进一期;`CarrierAdapter` 的 Manual 实现(人工录 tracking no、人工上传 POD)保证报价—预订—签收链路不断 |
| 大版块合并的集成风险 | 冻结区 + 契约逐字 + Fake 顶上;检查点按顺序不许跳;检查点合出大冲突 = 有人越界,按 ownership 回退 |
| 有人越界改了非 owner 目录 | Integrator 检测到即停,回退越界文件 |
| 事件消费失败导致费用缺失 | Outbox 同事务 + inbox 幂等 + 重试 + 失败队列告警(A31);验收脚本第 10 步专门制造一次失败 |
| 服务器未就绪 | 全程本地 + GitHub 开发不受影响;演示可在任一临时环境跑 |
| 周仓储费口径争议 | 以客户价目表为准:整周、按托盘类型;部分周与当日进出规则见 §4.2 快照,口径问题问客户,不自行发明 |
| PDF 读单(A12)效果差 | 保留"上传 → 人工确认草稿单",解析准确率不作验收项 |

---

# 附录 A · 取舍速查

| 采用(原样) | 简化采用 | 不采用 |
|---|---|---|
| Job 主线(CargoWise)<br>Charge Code 目录与费率快照(CargoWise · Extensiv)<br>费用归集到 Job,发票可按 Job 或跨 Job 合并(CargoWise · CartonCloud)<br>退货先验收再入库(CartonCloud · Extensiv)<br>异常中心 / 文档中心 / 集成监控(CargoWise · Extensiv)<br>多货主隔离(CartonCloud)<br>库存流水不可变(Extensiv)<br>作业即计费(CartonCloud)<br>费率每维度都是字段(Extensiv)<br>签收即闭环(TransVirtual)<br>买卖价挂同一票货(CargoWise)<br>门户同一套代码两种视图(CargoWise Neo)<br>数据层隔离(CartonCloud)<br>审计留痕(CargoWise)<br>GST 在账单层加(CartonCloud) | Container 只作 ASN 下可选物理对象(柜号 / 柜型 / 拆柜方式 / 毛重 / 行数),不做柜级生命周期与报关(Magaya 只取概念)<br>任务化执行 → 拣货任务 + VAS warehouse_tasks,不做规则引擎(Microlistics)<br>波次 → 只取概念,不做路径算法(Logiwa)<br>快照计费 → 周期改为**周**(Extensiv)<br>多承运商 → 经 Transdirect / EIZ 聚合平台落地,舍自建直连(MachShip 为参照)<br>运费对账 → 预期成本取自报价快照,比对可先导出<br>账单审核 → 填单时核对,不设独立审核工序(Extensiv)<br>集成 → 留接口不做连接器市场(Extensiv)<br>报表 → 内外两套固定视角,不做构建器 | SKU / 产品目录(全部对标产品)<br>客户账户 / 押金 / 信用额度 / 往来账<br>重新码托与 FBA 贴标服务(系统箱标与 connote 的 label 费照收)<br>**本版决定不做(2026-09-01):** P0/P1/P2 优先级标签、轻量 cost_items(Job 利润按 收入 − 运输成本 口径)、Palletising 任务与计费、FBA 预约字段、平台安全内控加固(评审第 26 条)、上线迁移方案(评审第 27 条)、全部行业建议<br>头程运输(China→AU 航段、BL、里程碑)<br>序列号级库存追踪(只存扫描记录供计费与 packing list)<br>多币种<br>双层 Handling Unit(拆托并托层级)<br>批次效期 FIFO/FEFO<br>补货与库位优化(Logiwa)<br>电商平台连接器(Extensiv)<br>路线与装载优化(Descartes)<br>快递面单与 B2C 小包(Shippit)<br>转运网络 / 分拨中心(TransVirtual)<br>实时 GPS(Descartes)<br>内置总账(CargoWise · Magaya)<br>多币种(CargoWise)<br>离线模式与原生 App |

---

# 附录 B · 对标公司总结:为什么选这些公司,优势在哪

## B.1 选择逻辑

对标公司按三条标准筛选:**① 与我们面对同一类问题**(3PL 多货主、整柜进口、澳洲本地配送与分包);**② 产品可考察**(有公开资料、demo 或试用,能看到具体做法而非只看宣传);**③ 优势互补** —— 没有一家在所有能力上都最强,所以按能力分项对标,而不是整体模仿一家。被排除的公司(Borderless360、Deposco、Infoplus 等)主要是因为不满足 ②:业务模式以服务为主、产品细节无法验证。

## B.2 公司与优势

| 公司 | 为什么选它 | 核心优势(机制) | 我们采用的部分 |
|---|---|---|---|
| **CartonCloud**(澳洲) | 与我们客户群完全重合的中小 3PL SaaS,澳洲本土,唯一需正面面对的竞品 | 出身自家 3PL:多货主隔离从第一行代码起就在数据层;作业动作自动生成费用且"动作 → 费用"是配置而非代码;GST 与 Xero/MYOB 原生;仓库工人与司机全部在手机上作业 | 多货主隔离、作业即计费、GST 处理、移动优先、订单多来源接入、订单类型分离、库内管理 |
| **CargoWise / WiseTech**(澳洲) | 澳洲成长出的全球货代标准,架构与定价的范本 | "一个数据库":所有模块写同一库,减少数据复制,但仍需业务对账;Job 为中心,收入与成本挂同一条记录;Neo 门户与主系统共用数据与权限;面向海关与审计的留痕是底层能力 | 模块化单体 + 单库、Job 主线与 Job 利润、Charge Code 目录、按 Job 开票、Job 工作台与异常统一入口、门户同一套代码两种视图、审计留痕 |
| **Extensiv / 3PL Central**(美国) | 北美最大中小 3PL WMS,3PL 计费能力被视为行业标杆 | 费率的每一个变化维度(活动/单位/阶梯/最低/附加/手工)都是字段,不写死在代码;库存流水不可变、余额永远等于流水累加;账单发出前有审核;集成做成独立产品 | 费率颗粒度与版本快照、库存流水对账、快照计费、账单控制项、退货验收、客户级订单规则、集成监控 |
| **TransVirtual**(澳洲) | 为承运商而非仓库写的 TMS,澳洲本土,分包场景最贴 | 建模对象是"一辆车的一天"(有序停靠点)而非"一件包裹的旅程";签收即闭环 —— 一个动作同时更新状态、通知客户、生成运费;按 consignment 计费 | run = 车 + 停靠点、司机手机页与 POD、签收触发多事件、consignment note 与标签 |
| **MachShip**(澳洲) | 澳洲承运商聚合平台,是二期承运商对接的直接对象 | 把承运商对接这条"维护跑步机"整体接过去:一次对接覆盖数百家承运商的比价、下单、打单、追踪、对账;服务等级驱动价格与时效;账单与预期逐票比对 | 报价先于执行的 transport option framework(经 Transdirect / EIZ 落地)、服务等级建模、成本 × 加成定价、运费对账、承运商绩效报表 |
| **Magaya**(美国) | 服务 NVOCC / CFS / 货代,整柜拆柜是它的日常场景 | 柜是一级单据:有自己的号、生命周期与费用项,拆柜差异逐票记录;LiveTrack 客户自助查货与对账 | ASN 的柜型与拆柜方式、柜号关联(只取概念,不做柜级生命周期)、订单页时间线呈现 |
| **Microlistics**(澳洲,WiseTech 旗下) | 澳洲本土企业级 WMS,任务化执行的成熟范本 | 系统决定工人下一步做什么:作业拆成任务按库位路径下发,完成即回写,新人无需懂业务即可上工 | 拣货单作为按库位排序、逐条确认的任务列表(舍规则引擎) |
| **Logiwa**(美国) | 高单量电商履约 WMS,波次与拣货算法的参照 | 波次算法与拣货路径优化,单量越大收益越明显 | 只采用"波次"概念(按客户/承运商/送达日批量释放),不采用算法 |
| **Descartes**(加拿大) | 承运商与海关数据网络,集成即产品的极端案例;同时是"拼盘式产品"的反面教材 | 整个公司的资产是连接网络本身;路线优化成熟 | 集成能力作为产品价值的思路(留接口);路线优化明确不采用 |
| **Shippit**(澳洲) | 澳洲零售侧承运商聚合,服务等级与追踪通知的参照 | 服务等级同时决定运费、时效与可用承运商;收件人追踪通知体验 | 服务等级作为 shipment 一级字段;快递面单与 B2C 功能明确不采用 |

## B.3 结论

我们的产品不是任何一家的复制,而是把上述各家在各自最强能力上的机制组合起来,并按三条判据(§0.4)做了取舍:**CartonCloud 提供 3PL 业务骨架与计费逻辑,CargoWise 提供架构与利润归集,Extensiv 提供费率颗粒度与流水纪律,TransVirtual 与 MachShip 提供运输侧的执行闭环与承运商编排,Magaya 提供"柜为 ASN 下可选对象"的思路(不取其柜级生命周期)。** 在这些之外,我们有三点是对标产品都不具备的:按货物行(无 SKU 主档)计库存、中文界面与中国头程客户视角、以及整柜进口 + FBA 派送的作业场景 —— 这三点构成产品的差异化。

---

*v4.5 · 2026-09-01 · 唯一计划文件,无待确认项。*
