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
        // Audit 2026-09-22 FIN-10 (CR #140): 待开票 counts exactly the unbilled pool's rows; rows already on a draft are shown apart.
        'counts' => '待开票 :pending(未开票池)· 草稿中 :drafted · 待报价 / 复核 :needs_review · 已开票 :invoiced · 已冲销 :reversed', 'drafts_link' => '草稿发票',
        'invoice' => '发票', 'in_draft' => '草稿中',
        'date' => '日期', 'client' => '客户', 'job' => 'Job', 'code' => '费用编码', 'description' => '说明', 'qty' => '数量', 'uom' => '单位', 'rate' => '单价', 'amount' => '金额(不含 GST)',
        'status' => '状态', 'source' => '来源', 'card' => '价目表', 'manual' => '手工', 'review_queue' => '待报价 / 复核队列',
        // Audit 2026-09-22 FIN-03 (CR #132): missing-rate rows now land here too (amount 0, out of the pool) instead of vanishing behind an exception.
        'review_hint' => '停在这里的费用行金额为 0,不进未开票池、不上发票:POA(面议)、超出阈值(如拆柜超 20 行、柜重超 22.5 t)、缺费率(客户价目表和标准价目表都没有这一项——同时在异常中心有一条「缺费率」异常),以及手工加费未填金额的行。财务填金额「确认金额」后费用行进入未开票池;缺费率行确认时自动关闭对应异常。补好价目表后重跑同一事件也会自动定价并关闭异常。',
        'review_amount' => '金额 (AUD)', 'review_note' => '说明(必填)', 'review_do' => '确认金额', 'reviewed' => '金额已确认,费用行可开票。',
        'missing_rate_badge' => '缺费率', 'suggested_amount' => '建议 :amount(运输客户价,已预填)', 'no_suggested_amount' => '无建议金额,请按价目表 / 报价填写',
        'review_client_only' => '只显示客户「:name」的待复核行 →', 'review_all' => '全部',
        'manual_title' => '手工加费', 'manual_hint' => '一次性费用必须填原因;留空金额则按价目表自动计价。', 'manual_reason' => '原因(必填)', 'manual_amount' => '金额(留空按价目表)', 'manual_add' => '添加费用',
        'manual_added' => '手工费用 #:id 已添加(:status)。', 'reverse' => '冲销', 'reverse_reason' => '冲销原因', 'reversed' => '费用 #:id 已冲销。', 'not_reversible' => '该费用行不能冲销(已是冲销或已冲销)。',
        // Audit 2026-09-22 FIN-09 / FIN-16 (CR #140): manual-charge form (blank code, 费用日期, Job prefill) and the credit-note path for invoiced charges.
        'select_code' => '— 请选择费用编码 —', 'charge_date' => '费用日期', 'charge_date_hint' => '费用归属的日期(默认今天,不能晚于今天);月初补录上月费用请改成上月日期,才会进上月账期。',
        'manual_job_filter' => '只显示客户「:name」的 Job(从 Job 页带入)', 'manual_all_jobs' => '全部 Job',
        'to_credit_note' => '去开冲减单', 'to_credit_note_hint' => '已开票的费用只能在发票上开 credit note(冲减单),不能冲销——否则客户会被退两次。',
        'on_draft_hint' => '已在草稿中:到草稿页处理(放弃草稿后才能冲销)。',
        'empty' => '没有费用行。', 'snapshot' => '计算快照', 'from' => '从', 'to' => '到',
        'errors' => [
            'review_only' => '只有「待报价 / 复核」状态的费用行可以人工定价。',
            'invoiced_use_credit_note' => '费用 #:id 已在发票 :no 上开出,不能冲销;请在该发票页开 credit note(冲减单)冲减,否则客户会被退两次。',
            'on_draft' => '费用 #:id 已进入草稿 :no,不能冲销;请先放弃该草稿(费用回到未开票池)再冲销。',
        ],
    ],
    'exceptions' => ['missing_rate' => '费用编码 :code(数量 :qty)缺费率:客户价目表和标准价目表都没有这一项,请补充费率或人工定价。', 'missing_rate_priced' => '价目表已补 :code,重跑事件后自动定价 :amount。', 'missing_rate_superseded' => '此缺费率行已作废(:reason);事件的新版本若仍缺费率会另开异常。'],
    'charge_statuses' => ['pending' => '待开票', 'needs_review' => '待报价 / 复核', 'approved' => '已确认', 'invoiced' => '已开票', 'disputed' => '有争议', 'reversed' => '已冲销'],
    'categories' => ['warehouse' => '仓库操作', 'vas' => '增值服务', 'transport' => '运输', 'storage' => '仓储', 'other' => '其它'],

    'unbilled_period' => ['period_from' => '账期从', 'period_to' => '到', 'scope' => '包含费用', 'group_by' => '分组方式', 'draft_period' => '按账期生成草稿', 'period_hint' => '该客户设置:账期:period,发票:grouping。可临时改成任意区间(一周 / 两周 / 自定义)和分组。', 'scopes' => ['service' => '服务费(不含仓储)', 'storage' => '仓储费', 'all' => '全部费用'], 'presets' => ['this_week' => '本周', 'last_week' => '上周', 'last_fortnight' => '前两周', 'this_month' => '本月', 'last_month' => '上月']],
    'unbilled' => ['title' => '未开票池', 'hint' => '已完成作业但尚未进入发票的费用,按客户 / Job 汇总。整柜客户按 Job 开服务发票;尾程客户月底汇总;仓储费按周。', 'draft_job' => '按此 Job 开票', 'draft_monthly' => '月结汇总开票', 'draft_storage' => '本周仓储费开票', 'period_from' => '期间从', 'period_to' => '到', 'week' => '周内任一天', 'empty' => '没有未开票费用。', 'lines' => '行数', 'review_pending' => '另有 :n 条待报价 / 复核,不在此池中。',
        'service_part' => '服务费', 'storage_part' => '仓储费', 'storage_only' => '只有仓储费:按周开票(下方「本周仓储费开票」或「按账期」选仓储费)', 'storage_note' => '另有仓储费 :count 行 / :amount,按周开票,不在此 Job 发票内',
        // Audit 2026-09-22 FIN-03: per-client badges for what is NOT in the pool yet.
        'missing_rate_count' => '缺费率 :n', 'review_count' => '待复核 :n', 'attention_hint' => '金额为 0、不在此池中:补费率或人工定价后才进入发票。', 'attention_title' => '只有未定价费用的客户',
        'missing_rate_total' => '另有 :n 条缺费率异常未处理。',
        // Audit 2026-09-22 FIN-10 (CR #140): a Job already on an open draft is not drafted twice.
        'has_draft' => '已有草稿 :no(:amount)', 'append_to_draft' => '并入该草稿', 'has_draft_hint' => '该 Job 已有一张草稿发票,不再另开;「并入该草稿」把这些新费用加进去并重算金额。'],
    'errors' => [
        'company_abn_missing' => '开票方 ABN 未配置(config/erp.php company,.env COMPANY_ABN),税务发票必须印开票方 ABN,补齐后才能开出。',
        'no_unbilled_job' => '该 Job 没有未开票的服务费(仓储费按周开票,不在 Job 发票内)。',
        'no_unbilled_period' => '该客户在此账期内没有符合所选范围的未开票费用,请调整账期或「包含费用」。',
        'no_unbilled_storage_week' => '该客户在那一周没有未开票的仓储费。',
        'already_issued' => '发票 :no 已是「:status」状态,不能再次开出。',
        'only_drafts_discard' => '只有草稿可以放弃;已开出的发票只能用 credit note 冲减。',
        'payment_issued_only' => '只能对已开出的发票登记收款。',
        'payment_positive' => '收款金额必须大于 0。',
        // Audit 2026-09-22 FIN-13 / FIN-10 (CR #140)
        'payment_exceeds_outstanding' => '收款金额 :amount 超过该发票未结金额 :outstanding,未登记;请按未结金额登记,多收部分另行处理。',
        'payment_void_row' => '#:id 是一条作废记录,不能再作废。',
        'payment_already_voided' => '收款 #:id 已作废过,不能重复作废。',
        'only_drafts_append' => '发票 :no 不是草稿,不能并入新费用;已开出的发票请按 Job 另开补充发票。',
        'append_storage_draft' => '仓储费草稿不接受服务费;服务费请按 Job 或按账期另开草稿。',
        'append_other_client' => '该 Job 属于另一个客户,不能并入这张草稿。',
    ],

    'invoices' => [
        'title' => '发票', 'no' => '发票号', 'type' => '类型', 'client' => '客户', 'period' => '期间', 'status' => '状态', 'issued_at' => '开出日期', 'due_at' => '到期', 'overdue' => '逾期', 'subtotal' => '小计(不含 GST)', 'gst' => 'GST', 'total' => '合计',
        'paid' => '已收', 'outstanding' => '未结', 'lines' => '发票行', 'jobs' => '包含 Job', 'issue' => '开出发票', 'issued' => '发票 :no 已开出。', 'drafted' => '草稿已生成,请核对后开出。', 'discard' => '放弃草稿(费用回到未开票池)', 'discarded' => '草稿已放弃。',
        'pdf' => '下载 PDF', 'payments' => '收款记录', 'record_payment' => '登记收款', 'payment_amount' => '金额 (AUD)', 'paid_at' => '收款日期', 'method' => '方式', 'reference' => '参考号', 'payment_recorded' => '收款已登记。',
        // Audit 2026-09-22 FIN-13 (CR #140): the amount box is pre-filled with the balance; a wrong receipt is voided by a negative twin, never deleted.
        'payment_amount_hint' => '已预填未结金额;不能超过未结金额。', 'void_payment' => '作废收款', 'void_reason' => '作废原因(必填)', 'payment_voided' => '收款 #:id 已作废(记为一条负数收款),发票已收 / 未结 / 状态已重算。', 'voided_badge' => '已作废', 'void_of' => '作废收款 #:id',
        // Audit 2026-09-22 FIN-10 (CR #140): a draft is a snapshot — new charges are listed and joined on request, they never slip in unnoticed.
        'bill_to' => '开票对象(开票时快照)', 'draft_hint' => '草稿是生成那一刻的费用快照:之后新产生的费用留在未开票池,不会自动进入本草稿(有的话列在下方,点「并入本草稿」加入);开出后冻结,只能用 credit note 冲减。', 'empty' => '没有发票。',
        'new_charges_for_job' => ':job 另有 :n 条新服务费(:amount)还在未开票池,未并入本草稿', 'append_to_draft' => '并入本草稿', 'appended' => '已把 :n 条费用并入草稿 :no,金额已重算。',
        // Audit 2026-09-22 FIN-03: the draft page warns (does not block) about unpriced items in the same Job / period.
        'review_warning_title' => '提醒:本发票范围内还有 :n 项未定价费用,不在这张发票里', 'review_warning_hint' => '待复核行定价后进入未开票池(再按 Job / 账期开草稿或补充发票);缺费率异常需补价目表或人工定价。只是提醒,不阻止开出。',
        'review_item' => '费用行 #:id :code · 数量 :qty · :job', 'exception_item' => '异常 #:id · :job · :message', 'review_link' => '待复核队列', 'exceptions_link' => '异常中心(缺费率)',
        'types' => ['service' => '服务发票', 'storage' => '周仓储', 'supplementary' => '补充发票', 'monthly' => '月结汇总'],
        'group_by' => ['job' => '按 Job 分组', 'order' => '按订单分组'],
        'statuses' => ['draft' => '草稿', 'issued' => '已开出', 'part_paid' => '部分收款', 'paid' => '已收款', 'void' => '作废'],
        'methods' => ['bank' => '银行转账', 'card' => '刷卡', 'cash' => '现金', 'other' => '其它'],
    ],
    'credit_notes' => [
        'title' => 'Credit note(冲减单)', 'new' => '新建 credit note', 'reason' => '原因(必填)', 'line_amount' => '冲减金额(不含 GST)', 'drafted' => 'Credit note #:id 草稿已建立并送审批(需第二人批准)。', 'issue' => '开出 credit note', 'issued' => 'Credit note :no 已开出。', 'status' => '状态',
        'approved_badge' => '已批准,可开出', 'pending_hint' => '审批中 → 审批中心', 'no_approval_hint' => '尚无待审批申请 → 审批中心',
        'remaining' => '可冲减 :amount', 'preselected_hint' => '从费用列表「去开冲减单」进来:已带入该费用行的可冲减余额,请核对金额、填原因后提交(需第二人批准)。',
        'errors' => ['not_issued' => '只能对已开出的发票建 credit note。', 'positive_amount' => 'Credit note 金额必须大于 0:至少填一行冲减金额。', 'not_approved' => '这张 credit note 还没有第二人批准,批准后才能开出。',
            'exceeds_line' => '发票行「:code · :description」最多还可冲减 :max(不含 GST;已有 credit note 已扣除),填写的 :amount 超出。'],
        'statuses' => ['draft' => '草稿(待审批)', 'approved' => '已批准', 'issued' => '已开出', 'cancelled' => '已取消'],
    ],

    'receivables' => ['title' => '应收 / 未结余额', 'client' => '客户', 'open_invoices' => '未结发票', 'outstanding' => '未结金额', 'overdue' => '其中逾期', 'hint' => '按发票汇总:合计 − 已收 − 已开出的 credit note。逾期只是提示,不影响预订和发运。', 'empty' => '没有未结发票。'],

    'charge_codes' => ['title' => '费用编码目录', 'code' => '编码', 'category' => '类别', 'uom' => '默认单位', 'description' => '对客描述', 'tax' => '税务', 'rules' => '触发规则', 'hint' => '编码是契约(contracts/charge-codes.md);价目表只给编码定价。'],

    'rate_cards' => [
        'title' => '价目表', 'standard' => '标准价目表', 'client_card' => '客户专属', 'name' => '名称', 'version' => '版本', 'effective_from' => '生效日', 'effective_to' => '失效日', 'status' => '状态', 'items' => '费率项', 'client' => '客户',
        'create' => '新建客户专属价目表', 'created' => '价目表已创建(草稿)。', 'new_version' => '复制为新版本', 'new_version_created' => '新版本 v:version 已创建(草稿),改价后送审批。', 'notes' => '备注',
        'item_saved' => '费率项已保存。', 'request_activation' => '送审批(第二人批准后生效)', 'activation_requested' => '已送审批。', 'activate' => '生效(替换旧版本)', 'activated' => '版本 v:version 已生效,旧版本已归档;历史费用金额不变。',
        'approved_badge' => '已批准,可生效', 'pending_badge' => '审批中', 'draft_hint' => '草稿可编辑费率;送审批后锁定,生效后不可修改,改价请复制为新版本。', 'add_item' => '添加费率项',
        // Audit 2026-09-22 FIN-08 (CR #140): a submitted draft is frozen; the draft page shows what changes against the previous version.
        'locked_badge' => '费率已锁定', 'locked_hint' => '价目表已送审批(或已批准),费率已锁定,不能再改:批准后点「生效」;要改价请先到审批中心撤回申请,或「复制为新版本」在新草稿里改。',
        'diff_title' => '与 v:version 相比:', 'diff_counts' => '新增 :added · 修改 :changed · 删除 :removed', 'diff_only' => '只看改动', 'diff_added' => '新增', 'diff_changed' => '已修改', 'diff_removed' => '已删除',
        'diff_none' => '与 v:version 完全相同,还没有改价。', 'diff_first_version' => '首个版本,没有可比较的旧版本。', 'diff_removed_title' => '本版删除的费率项(v:version 有,本版没有)',
        'rate' => '单价 (AUD)', 'min_charge' => '最低收费', 'poa' => 'POA(面议)', 'pricing_mode' => '计价方式', 'markup' => '加成 %', 'pallet_class' => '托盘类型', 'band' => '重量分档 (kg)', 'zone' => '分区', 'service_level' => '服务等级', 'thresholds' => '阈值参数 (JSON)', 'thresholds_placeholder' => '阈值参数 (JSON),例如 {"tailgate_weight_kg":25}',
        'band_min' => '下限 (kg)', 'band_max' => '上限 (kg)', 'approval_note' => ':name v:version,:date 起生效',
        // CHANGE_REQUESTS #126
        'warehouse' => '仓库', 'all_warehouses' => '全部仓库',
        'tier_hint' => '底层库位附加费 WH-STORAGE-TIER-PLT-WK:计价方式选「百分比附加」,加成 % 在阈值 min_percent – max_percent 之间(标准表 10 – 20);可按仓库(墨尔本 / 悉尼)各设一行,指定仓库的行只用于该仓库的每周仓储计费并优先于「全部仓库」的行;阈值(托盘尺寸档、单价预选等)和其他费用始终读「全部仓库」的行。tier_value_threshold_cents = 单价达到该值(分)时导入清单预选底层。',
        'errors' => [
            'tier_percent_only' => '底层库位附加费 (WH-STORAGE-TIER-PLT-WK) 只能用「百分比附加」计价。',
            'tier_band' => '底层库位附加费的加成必须在 :min% – :max% 之间(价目表阈值 min_percent / max_percent)。',
            'tier_percent_required' => '底层库位附加费必须填写加成 %。',
            'active_immutable' => '已生效的价目表不能改费率,请「复制为新版本」后再改价。',
            'draft_only_items' => '只有草稿状态的价目表可以添加费率项。',
            'draft_only_submit' => '只有草稿状态的价目表可以送审批。',
            'draft_only_activate' => '只有草稿状态的价目表可以生效。',
            'not_approved' => '这个版本还没有第二人批准(PLT-7),批准后才能生效。',
            'locked_for_approval' => '价目表 v:version 已送审批(或已批准),费率已锁定,不能修改;请先到审批中心撤回申请,或「复制为新版本」后在新草稿里改价。',
        ],
        'statuses' => ['draft' => '草稿', 'active' => '生效', 'superseded' => '已归档'], 'pricing_modes' => ['fixed' => '固定单价', 'cost_plus' => '成本 + 加成', 'percent' => '百分比附加'],
    ],

    'quotes' => [
        'title' => '报价', 'create' => '新建报价', 'created' => '报价 :no 已生成。', 'no' => '报价号', 'client' => '客户', 'stage' => '阶段', 'valid_until' => '有效期至', 'status' => '状态', 'total' => '合计(含 GST)', 'lines' => '报价行',
        'line_code' => '费用编码', 'line_qty' => '数量', 'line_weight' => '单箱重量 (kg,拣货分档用)', 'line_cost' => '第三方成本 (AUD,cost_plus 用)', 'line_base' => '基数金额 (AUD,百分比附加用,如燃油附加费的运费基数)', 'add_line' => '再加一行', 'no_lines' => '至少填一行有效的费用编码和数量。',
        'poa_flag' => 'POA / 缺费率:需财务人工定价', 'status_saved' => '状态已更新。', 'notes' => '备注', 'empty' => '没有报价。', 'assumptions' => '计算依据',
        'stages' => ['preliminary' => '初步', 'final' => '最终'], 'statuses' => ['draft' => '草稿', 'sent' => '已发送', 'accepted' => '已接受', 'rejected' => '已拒绝', 'expired' => '已过期'],
    ],

    'job_panel' => ['title' => '费用与利润', 'revenue' => '收入(费用行,不含 GST)', 'cost' => '成本(承运商)', 'margin' => '毛利', 'pending_cost' => '成本由运输模块 (M5) 提供', 'open' => '在费用列表中查看', 'manual_charge' => '手工加费'],
];
