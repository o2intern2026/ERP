<?php

// Owner: seat C. All MasterData UI strings live here — no hardcoded Chinese in Blade (AGENTS.md).
return [
    'title' => '主数据',
    'nav' => '主数据',
    'placeholder' => '模块占位页 —— 功能在后续检查点交付。',
    'saved' => '已保存。',
    'empty' => '暂无记录。',

    'clients' => [
        'title' => '客户',
        'create' => '新建客户',
        'edit' => '编辑客户',
        'billing_section' => '计费设置(每个客户可不同)',
        'payment_terms_hint' => 'prepaid(预付)/ eom(月末)/ net_N(N 天,如 net_30)。只决定发票到期日,不会阻止预订或发运。',
        'markup_hint' => '第三方运费的默认加成百分比;价目表可按承运商 × 服务等级覆盖。',
        'cutoff_hint' => '当天发运的截单时间;超过后的加急派送按价目表收加急费。',
        'standard_card_hint' => '新客户默认绑定标准价目表;价目表本身在 M6 计费模块中维护。',
    ],
    'suppliers' => ['title' => '供应商', 'create' => '新建供应商', 'edit' => '编辑供应商'],
    'carriers' => ['title' => '承运商', 'create' => '新建承运商', 'edit' => '编辑承运商'],

    'fields' => [
        'code' => '编码',
        'name' => '名称',
        'abn' => 'ABN',
        'leg_type' => '业务类型',
        'contact_name' => '联系人',
        'contact_phone' => '联系电话',
        'contact_email' => '联系邮箱',
        'billing_email' => '账单邮箱',
        'address' => '地址',
        'suburb' => '城区',
        'state' => '州',
        'postcode' => '邮编',
        'status' => '状态',
        'payment_terms' => '账期',
        'invoice_mode' => '开票模式',
        'default_markup_percent' => '默认加成 %',
        'dispatch_cutoff_time' => '发运截单时间',
    ],

    'leg_types' => ['first_leg' => '头程', 'last_leg' => '尾程', 'both' => '头程 + 尾程'],
    'invoice_modes' => ['per_job' => '按 Job 开票', 'monthly' => '月结汇总'],
    'statuses' => ['active' => '启用', 'inactive' => '停用'],
];
