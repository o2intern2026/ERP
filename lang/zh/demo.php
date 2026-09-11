<?php

// Owner: seat C. Console strings for `php artisan demo:run` — the automatic demo story (lead request 2026-09-11,
// contracts/CHANGE_REQUESTS.md #113). Console output is UI too: nothing here is hardcoded in the command.
return [
    'description' => '在当前数据库上自动生成一条端到端演示业务链(只新增、不清库,可重复执行);--until 可停在任一阶段,余下步骤留给测试人员手动完成',

    'options' => [
        'client' => '客户代码',
        'warehouse' => '仓库代码',
        'lines' => '预报单货物行数(至少 2)',
        'orders' => '派送订单数(1 到 --lines 之间)',
        'tag' => '本次演示标签(默认 DEMO-<ymd-His>),所有单据、唛头、收货人都带上它',
        'until' => '停在哪个阶段(asn / received / putaway / orders / confirmed / waved / picked / packed / booked / dispatched / delivered / invoiced)',
        'json' => '以 JSON 输出汇总(脚本用)',
    ],

    'stages' => [
        'asn' => '预报单',
        'received' => '收货',
        'putaway' => '上架',
        'orders' => '生成派送订单',
        'confirmed' => '确认订单',
        'waved' => '释放波次',
        'picked' => '拣货',
        'packed' => '打包',
        'booked' => '运输报价与订舱',
        'dispatched' => '发运交接',
        'delivered' => '送达',
        'invoiced' => '开票',
    ],

    'console' => [
        'start' => '开始自动演示 · 标签 :tag · 客户 :client · 仓库 :warehouse · 停在「:until」',
        'refused' => '拒绝执行::reason',
    ],

    'errors' => [
        'unknown_stage' => '未知的 --until 阶段「:stage」,可选::stages',
        'lines' => '--lines 至少为 2(当前 :lines)。',
        'orders' => '--orders 必须在 1 与 --lines(:lines)之间(当前 :orders)。',
        'tag' => '--tag 只能包含大写字母、数字和连字符,长度 3–40(当前「:tag」)。',
        'client' => '客户代码「:code」不存在。',
        'warehouse' => '仓库代码「:code」不存在或已停用。',
        'users' => '缺少角色账号 :emails(请先执行 php artisan db:seed --class=PlatformSeeder)。',
        'locations' => '仓库 :code 没有可用的「:type」库位。',
        'no_receipt' => '预报单 :asn 没有打开的入库单批次。',
        'receipt_pdf' => '入库单 :no 已完成,但没有生成 PDF 文档。',
        'asn_not_putaway' => '预报单 :asn 状态为「:status」,未完成上架。',
        'orders_count' => '按唛头生成了 :got 张订单,预期 :want 张(被阻止 :blocked 组)。',
        'no_fulfilment' => '订单 :order 没有预留库存(fulfilments 为空),请查看缺货异常。',
        'no_task' => '订单 :order 没有拣货任务,波次未覆盖它。',
        'not_packed' => '订单 :order 状态为「:status」,未变为已打包,请检查 outbox_events。',
        'no_shipment' => '订单 :order 没有生成运单,请检查 outbox_events 是否有失败事件。',
        'no_manual_service' => '没有启用的人工报价承运服务(source=manual),无法订舱。',
    ],

    'reasons' => [
        'short' => '短装 — 柜内少 1 箱',
        'damaged' => '纸箱破损 1 箱,已隔离',
    ],

    'notes' => [
        'asn' => '自动演示 :tag',
        'receipt' => '自动演示 :tag — 整柜一批收完',
        'confirmed' => '自动演示 :tag:客服确认',
        'vehicle' => 'VAN-01 (:tag)',
        'recipient' => '收货员(演示 :tag)',
    ],

    'sources' => ['own_fleet' => '自有车队', 'karrio' => 'Karrio', 'manual' => '人工报价', 'transdirect' => 'Transdirect', 'eiz' => 'EIZ'],
    'handed_to' => ['driver' => '司机(自有车队)', 'carrier' => '承运商'],
    'label' => ['yes' => '面单可打印', 'no' => '无面单'],

    'steps' => [
        'asn' => ':asn · 40 尺整柜 :container · :lines 行货物 / :marks 个唛头 · 已登记到货',
        'received' => ':receipt · 拆柜任务完成 · 收 :received 箱(短装 1 箱、破损 1 箱)· 入库完成,入库单 PDF 已生成 · 差异异常 :exceptions 条',
        'putaway' => ':units 个单元全部上架(托盘 :pallets、散箱 :cartons、隔离 :quarantine)· 预报单 → 已上架',
        'putaway_partial' => '已上架 :done / :units 个单元;:label 留在收货区,请在预报单页完成最后一次上架',
        'orders' => ':count 张派送订单::orders',
        'confirmed' => ':count 张订单已确认,库存已预留(即时发运)',
        'waved' => ':wave · :tasks 个拣货任务',
        'picked' => ':tasks 个任务全部确认;:order 少拣 1 箱 → 拣货短缺异常:exception',
        'packed' => ':count 张订单已打包(:packages 件包装,已量重量 / 尺寸)· 订单 → 已打包 · 运单进入最终报价',
        'booked_leg' => ':shipment(:source · :status:ref · :label)',
        'dispatched' => ':order 已交接给:who(托盘 :pallets、包装 :packages)· 运单 :shipment → :status',
        'delivered_driver' => ':order 由司机 :driver 签收送达(运单 :shipment)',
        'delivered_carrier' => ':order 承运商 POD 已上传,送达(运单 :shipment)',
        'invoiced' => ':invoice 已开出,合计 :total(含 GST :gst)· 未开票费用 :unbilled 条留在池中',
    ],

    'summary' => [
        'title' => '演示数据已生成 · 标签 :tag',
        'title_partial' => '演示在「:stage」步骤中断 · 标签 :tag',
        'columns' => ['item' => '项目', 'no' => '单号', 'status' => '状态', 'url' => '链接'],
        'items' => ['job' => 'Job', 'asn' => '预报单', 'receipt' => '入库单', 'order' => '订单', 'wave' => '波次', 'run' => '派送任务', 'shipment' => '运单', 'invoice' => '发票'],
        'notes' => '说明:',
        'next' => '建议接下来打开:',
        'stopped' => '按 --until=:stage 停在「:label」,余下步骤请手动完成。',
        'order_stopped' => '订单 :orders 停在「:status」,可在出库页继续。',
        'order_booked' => '订单 :order 已订舱(运单 :shipment),可在出库页做发运交接。',
        'order_dispatched' => '订单 :order 已发运(运单 :shipment),可在运单页 / 司机页录入 POD。',
        'unit_pending' => '库存单元 :label 尚未上架(在预报单页完成)。',
        'karrio_on' => 'Karrio 已配置并返回了报价;本次报价来源::sources。',
        'karrio_no_quote' => 'Karrio 已配置但没有返回报价(网关未启动?),第三方运输改用人工报价;本次报价来源::sources。',
        'karrio_off' => 'Karrio 未配置,第三方运输使用人工报价;本次报价来源::sources。',
        'pages' => [
            'asn' => '预报单页(收货 / 上架 / 生成订单)',
            'receipt' => '入库单(PDF)',
            'orders' => '订单列表',
            'outbound' => '出库看板(波次 / 拣货 / 打包 / 发运交接)',
            'shipment' => '运单 :no',
            'run' => '派送任务(司机页)',
            'invoice' => '发票 :no',
            'job' => 'Job 工作台 :no',
        ],
    ],
];
