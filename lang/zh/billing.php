<?php

// Owner: seat C. All Billing UI strings live here — no hardcoded Chinese in Blade (AGENTS.md).
return [
    'title' => '计费',
    'nav' => '费用',
    'nav_unbilled' => '未开票',
    'nav_invoices' => '发票',
    'nav_receivables' => '应收',
    'nav_rate_cards' => '价目表',
    'nav_quotes' => '报价',
    'placeholder' => '模块占位页 —— 功能在后续检查点交付。',
    'money' => 'AUD',

    'charges' => [
        'title' => '费用列表',
        'counts' => '待开票 :pending · 待报价 / 复核 :needs_review · 已开票 :invoiced · 已冲销 :reversed',
        'date' => '日期', 'client' => '客户', 'job' => 'Job', 'code' => '费用编码', 'description' => '说明', 'qty' => '数量', 'uom' => '单位', 'rate' => '单价', 'amount' => '金额(不含 GST)',
        'status' => '状态', 'source' => '来源', 'card' => '价目表', 'manual' => '手工', 'review_queue' => '待报价 / 复核队列', 'review_hint' => 'POA(面议)或缺费率的费用行,由财务填金额后才会进入发票。',
        'review_amount' => '金额 (AUD)', 'review_note' => '说明(必填)', 'review_do' => '确认金额', 'reviewed' => '金额已确认,费用行可开票。',
        'manual_title' => '手工加费', 'manual_hint' => '一次性费用必须填原因;留空金额则按价目表自动计价。', 'manual_reason' => '原因(必填)', 'manual_amount' => '金额(留空按价目表)', 'manual_add' => '添加费用',
        'manual_added' => '手工费用 #:id 已添加(:status)。', 'reverse' => '冲销', 'reverse_reason' => '冲销原因', 'reversed' => '费用 #:id 已冲销。', 'not_reversible' => '该费用行不能冲销(已是冲销或已冲销)。',
        'empty' => '没有费用行。', 'snapshot' => '计算快照', 'from' => '从', 'to' => '到',
        'errors' => ['review_only' => '只有「待报价 / 复核」状态的费用行可以人工定价。'],
    ],
    'exceptions' => ['missing_rate' => '费用编码 :code(数量 :qty)缺费率:客户价目表和标准价目表都没有这一项,请补充费率或人工定价。'],
    'charge_statuses' => ['pending' => '待开票', 'needs_review' => '待报价 / 复核', 'approved' => '已确认', 'invoiced' => '已开票', 'disputed' => '有争议', 'reversed' => '已冲销'],
    'categories' => ['warehouse' => '仓库操作', 'vas' => '增值服务', 'transport' => '运输', 'storage' => '仓储', 'other' => '其它'],

    'unbilled_period' => ['period_from' => '账期从', 'period_to' => '到', 'scope' => '包含费用', 'group_by' => '分组方式', 'draft_period' => '按账期生成草稿', 'period_hint' => '该客户设置:账期:period,发票:grouping。可临时改成任意区间(一周 / 两周 / 自定义)和分组。', 'scopes' => ['service' => '服务费(不含仓储)', 'storage' => '仓储费', 'all' => '全部费用'], 'presets' => ['this_week' => '本周', 'last_week' => '上周', 'last_fortnight' => '前两周', 'this_month' => '本月', 'last_month' => '上月']],
    'unbilled' => ['title' => '未开票池', 'hint' => '已完成作业但尚未进入发票的费用,按客户 / Job 汇总。整柜客户按 Job 开服务发票;尾程客户月底汇总;仓储费按周。', 'draft_job' => '按此 Job 开票', 'draft_monthly' => '月结汇总开票', 'draft_storage' => '本周仓储费开票', 'period_from' => '期间从', 'period_to' => '到', 'week' => '周内任一天', 'empty' => '没有未开票费用。', 'lines' => '行数', 'review_pending' => '另有 :n 条待报价 / 复核,不在此池中。',
        'service_part' => '服务费', 'storage_part' => '仓储费', 'storage_only' => '只有仓储费:按周开票(下方「本周仓储费开票」或「按账期」选仓储费)', 'storage_note' => '另有仓储费 :count 行 / :amount,按周开票,不在此 Job 发票内'],
    'errors' => [
        'no_unbilled_job' => '该 Job 没有未开票的服务费(仓储费按周开票,不在 Job 发票内)。',
        'no_unbilled_period' => '该客户在此账期内没有符合所选范围的未开票费用,请调整账期或「包含费用」。',
        'no_unbilled_storage_week' => '该客户在那一周没有未开票的仓储费。',
        'already_issued' => '发票 :no 已是「:status」状态,不能再次开出。',
        'only_drafts_discard' => '只有草稿可以放弃;已开出的发票只能用 credit note 冲减。',
        'payment_issued_only' => '只能对已开出的发票登记收款。',
        'payment_positive' => '收款金额必须大于 0。',
    ],

    'invoices' => [
        'title' => '发票', 'no' => '发票号', 'type' => '类型', 'client' => '客户', 'period' => '期间', 'status' => '状态', 'issued_at' => '开出日期', 'due_at' => '到期', 'overdue' => '逾期', 'subtotal' => '小计(不含 GST)', 'gst' => 'GST', 'total' => '合计',
        'paid' => '已收', 'outstanding' => '未结', 'lines' => '发票行', 'jobs' => '包含 Job', 'issue' => '开出发票', 'issued' => '发票 :no 已开出。', 'drafted' => '草稿已生成,请核对后开出。', 'discard' => '放弃草稿(费用回到未开票池)', 'discarded' => '草稿已放弃。',
        'pdf' => '下载 PDF', 'payments' => '收款记录', 'record_payment' => '登记收款', 'payment_amount' => '金额 (AUD)', 'paid_at' => '收款日期', 'method' => '方式', 'reference' => '参考号', 'payment_recorded' => '收款已登记。',
        'bill_to' => '开票对象(开票时快照)', 'draft_hint' => '草稿状态下金额可能仍会因新费用变化;开出后冻结,只能用 credit note 冲减。', 'empty' => '没有发票。',
        'types' => ['service' => '服务发票', 'storage' => '周仓储', 'supplementary' => '补充发票', 'monthly' => '月结汇总'],
        'group_by' => ['job' => '按 Job 分组', 'order' => '按订单分组'],
        'statuses' => ['draft' => '草稿', 'issued' => '已开出', 'part_paid' => '部分收款', 'paid' => '已收款', 'void' => '作废'],
        'methods' => ['bank' => '银行转账', 'card' => '刷卡', 'cash' => '现金', 'other' => '其它'],
    ],
    'credit_notes' => [
        'title' => 'Credit note(冲减单)', 'new' => '新建 credit note', 'reason' => '原因(必填)', 'line_amount' => '冲减金额(不含 GST)', 'drafted' => 'Credit note #:id 草稿已建立并送审批(需第二人批准)。', 'issue' => '开出 credit note', 'issued' => 'Credit note :no 已开出。', 'status' => '状态',
        'approved_badge' => '已批准,可开出', 'pending_hint' => '审批中 → 审批中心', 'no_approval_hint' => '尚无待审批申请 → 审批中心',
        'errors' => ['not_issued' => '只能对已开出的发票建 credit note。', 'positive_amount' => 'Credit note 金额必须大于 0:至少填一行冲减金额。', 'not_approved' => '这张 credit note 还没有第二人批准,批准后才能开出。'],
        'statuses' => ['draft' => '草稿(待审批)', 'approved' => '已批准', 'issued' => '已开出', 'cancelled' => '已取消'],
    ],

    'receivables' => ['title' => '应收 / 未结余额', 'client' => '客户', 'open_invoices' => '未结发票', 'outstanding' => '未结金额', 'overdue' => '其中逾期', 'hint' => '按发票汇总:合计 − 已收 − 已开出的 credit note。逾期只是提示,不影响预订和发运。', 'empty' => '没有未结发票。'],

    'charge_codes' => ['title' => '费用编码目录', 'code' => '编码', 'category' => '类别', 'uom' => '默认单位', 'description' => '对客描述', 'tax' => '税务', 'rules' => '触发规则', 'hint' => '编码是契约(contracts/charge-codes.md);价目表只给编码定价。'],

    'rate_cards' => [
        'title' => '价目表', 'standard' => '标准价目表', 'client_card' => '客户专属', 'name' => '名称', 'version' => '版本', 'effective_from' => '生效日', 'effective_to' => '失效日', 'status' => '状态', 'items' => '费率项', 'client' => '客户',
        'create' => '新建客户专属价目表', 'created' => '价目表已创建(草稿)。', 'new_version' => '复制为新版本', 'new_version_created' => '新版本 v:version 已创建(草稿),改价后送审批。', 'notes' => '备注',
        'item_saved' => '费率项已保存。', 'request_activation' => '送审批(第二人批准后生效)', 'activation_requested' => '已送审批。', 'activate' => '生效(替换旧版本)', 'activated' => '版本 v:version 已生效,旧版本已归档;历史费用金额不变。',
        'approved_badge' => '已批准,可生效', 'pending_badge' => '审批中', 'draft_hint' => '草稿可编辑费率;生效后不可修改,改价请复制为新版本。', 'add_item' => '添加费率项',
        'rate' => '单价 (AUD)', 'min_charge' => '最低收费', 'poa' => 'POA(面议)', 'pricing_mode' => '计价方式', 'markup' => '加成 %', 'pallet_class' => '托盘类型', 'band' => '重量分档 (kg)', 'zone' => '分区', 'service_level' => '服务等级', 'thresholds' => '阈值参数 (JSON)',
        'band_min' => '下限 (kg)', 'band_max' => '上限 (kg)', 'approval_note' => ':name v:version,:date 起生效',
        'errors' => [
            'active_immutable' => '已生效的价目表不能改费率,请「复制为新版本」后再改价。',
            'draft_only_items' => '只有草稿状态的价目表可以添加费率项。',
            'draft_only_submit' => '只有草稿状态的价目表可以送审批。',
            'draft_only_activate' => '只有草稿状态的价目表可以生效。',
            'not_approved' => '这个版本还没有第二人批准(PLT-7),批准后才能生效。',
        ],
        'statuses' => ['draft' => '草稿', 'active' => '生效', 'superseded' => '已归档'], 'pricing_modes' => ['fixed' => '固定单价', 'cost_plus' => '成本 + 加成', 'percent' => '百分比附加'],
    ],

    'quotes' => [
        'title' => '报价', 'create' => '新建报价', 'created' => '报价 :no 已生成。', 'no' => '报价号', 'client' => '客户', 'stage' => '阶段', 'valid_until' => '有效期至', 'status' => '状态', 'total' => '合计(含 GST)', 'lines' => '报价行',
        'line_code' => '费用编码', 'line_qty' => '数量', 'line_weight' => '单箱重量 (kg,拣货分档用)', 'line_cost' => '第三方成本 (AUD,cost_plus 用)', 'line_base' => '基数金额 (AUD,百分比附加用,如燃油附加费的运费基数)', 'add_line' => '再加一行', 'no_lines' => '至少填一行有效的费用编码和数量。',
        'poa_flag' => 'POA / 缺费率:需财务人工定价', 'status_saved' => '状态已更新。', 'notes' => '备注', 'empty' => '没有报价。', 'assumptions' => '计算依据',
        'stages' => ['preliminary' => '初步', 'final' => '最终'], 'statuses' => ['draft' => '草稿', 'sent' => '已发送', 'accepted' => '已接受', 'rejected' => '已拒绝', 'expired' => '已过期'],
    ],

    'job_panel' => ['title' => '费用与利润', 'revenue' => '收入(费用行,不含 GST)', 'cost' => '成本(承运商)', 'margin' => '毛利', 'pending_cost' => '成本由运输模块 (M5) 提供', 'open' => '在费用列表中查看'],
];
