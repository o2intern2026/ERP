# 全系统「有权限却做不下去」排查 · 2026-09-10

目标:找出让**有相应权限的用户**也无法完成操作的缺陷(隐藏空行导致校验失败、永远过不去的规则、点了进无权限页的按钮、前置条件死胡同、英文报错)。
方法:8 个模块各一位静态复核(逐个表单对照校验规则和服务前置条件)+ 1 个「照页面默认值提交」的动态检查;每条发现由独立复核者反驳一次,只保留实锤。共检查 203 个表单 / 操作,确认 62 条:

| 级别 | 数量 |
|---|---|
| 提示 / 体验问题 | 44 |
| 常见路径失败 | 15 |
| 卡死(有权限也无法完成) | 3 |

| 模块 | 数量 |
|---|---|
| Orders | 14 |
| Transport | 10 |
| Warehouse | 9 |
| Platform | 9 |
| Billing | 8 |
| Portal | 7 |
| Reports | 3 |
| MasterData | 2 |

已修复(2026-09-10):收货表单隐藏空行(第一条卡死)。其余待项目负责人排序。

## 卡死(有权限也无法完成)

### B1 · [Warehouse] 收货 form: the two pre-rendered hidden 库存单元 rows are submitted empty and fail units.*.carton_qty required|min:1 — a warehouse supervisor can never submit a receipt

- **页面**:GET/POST /warehouse/asns/{asn}/lines/{line}/receive — app/Modules/Warehouse/views/receiving/form.blade.php (+ ReceivingController::store)
- **受影响角色**:admin, warehouse_supervisor, warehouse_operator
- **复现**:Preconditions: an ASN in status booked/arrived/receiving with at least one line that has no stock units and no receipt line (it appears on /warehouse/receiving), and at least one active receiving-type location in that ASN's warehouse.

1. Sign in as warehouse_supervisor (admin or warehouse_operator reproduce identically).
2. Go to 待收货: GET /warehouse/receiving.
3. Click 收货 on any line → GET /warehouse/asns/{asn}/lines/{line}/receive. Only one 库存单元 row is visible; open DevTools and confirm two more `div.unit-row[hidden]` exist in the DOM with enabled `select[name="units[1][unit_type]"]` / `units[2][unit_type]` (value "pallet") and empty `units[1][carton_qty]` / `units[2][carton_qty]`.
4. Change nothing: 收货库位 = the prefilled receiving location, 实收箱数 = expected_cartons, 破损 = 0, row 0 箱数 = expected_cartons.
5. Press 提交收货.

Expected: the receipt saves and you are redirected to the ASN page with the 收货完成 flash.
Actual: HTTP 302 back to the form with validation errors "The units.1.carton_qty field is required." and "The units.2.carton_qty field is required." No goods receipt, no stock units, no ledger rows are created (verify: the line is still listed on /warehouse/receiving).

Variant: click 增加单元 once (row 1 becomes visible) and fill it — submit still fails with "The units.2.carton_qty field is required." for the remaining hidden row. The only way through is to type a bogus qty ≥ 1 into every hidden row, which creates spurious stock units and receipt ledger entries, since nothing validates that the unit carton_qty sum matches 实收箱数.

Contrast (works, proving the isolation): 无预报收货 at /warehouse/receiving/unplanned submits fine, because UnplannedReceivingController::store prunes blank rows with $request->merge(...) before validate() and its blade keeps extra rows in a <template>.
- **原因**:The HTML `hidden` attribute only hides a row, it does not disable its inputs, so `units[1][*]` and `units[2][*]` are always posted. Because each row's `<select name="units[i][unit_type]">` has a preselected default ('pallet'), the array elements 1 and 2 always exist, so Laravel runs `units.*` on them; `carton_qty` arrives as '' and `required` (plus `min:1`) fails. The controller already filters out zero-carton rows at line 82, so the `required`/`min:1` rules on the wildcard contradict the form's own design.
- **修法**:Drop blank unit rows before validation, exactly as AsnController::store and UnplannedReceivingController::store already do, and relax the wildcard rules: (1) in ReceivingController::store add `$request->merge(['units' => array_values(array_filter((array) $request->input('units', []), fn ($u) => filled($u['carton_qty'] ?? null))))];` as the first statement; (2) change `'units.*.carton_qty' => ['required','integer','min:1']` to `['required','integer','min:1']` applied only to the surviving rows (after the merge this is correct) and make `units.*.unit_type` `['nullable', Rule::in(...)]`; (3) set the number input's `min` to 1 so the browser matches the server. Alternatively render the extra rows inside a `<template>` and clone them in the 增加单元 handler (the 无预报收货 form at receiving/unplanned.blade.php:57 already does this correctly and can be copied).
- **工时**:约 0.5 小时 · 来源:static:Warehouse

### B2 · [Warehouse] 出库 board crashes (500) whenever a wave has open pick tasks — WarehouseTask has no wave() relation but the controller eager-loads it

- **页面**:GET /warehouse/outbound → app/Modules/Warehouse/Http/Controllers/OutboundController.php@index, views/outbound/index.blade.php
- **受影响角色**:warehouse_operator, warehouse_supervisor, admin, dispatcher, customer_service, finance
- **复现**:Preconditions: at least one from-stock order confirmed and allocated so it appears in 出库 → 待释放.

1. Log in as warehouse-operator (waop) — or ad/wasu; the route group `role:admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance` (app/Modules/Warehouse/routes.php:22,29) lets all six roles hit the page.
2. GET /warehouse/outbound — renders fine (no pick tasks yet).
3. Tick the allocated order in 待释放, pick the warehouse in the select, click 释放波次 (POST /warehouse/outbound/waves). OutboundService::releaseWave (line 63) creates a `pick` WarehouseTask with wave_id set and TaskService::create (line 22) forces status='pending'. You are redirected to the wave sheet, which renders OK.
4. Without confirming any pick line, navigate back to GET /warehouse/outbound (click 出库 in the nav, or the browser back button).
   → HTTP 500: `Illuminate\Database\Eloquent\RelationNotFoundException: Call to undefined relationship [wave] on model [App\Modules\Warehouse\Models\WarehouseTask]`.
5. Confirm every pick line of that wave (task becomes done) → /warehouse/outbound renders again. Any new release re-breaks it.

Note for the tester: the page only 500s when the 拣货中 query returns ≥1 row, because Builder::get() (vendor/laravel/framework/.../Eloquent/Builder.php:884) skips eager loading on an empty result set — that is exactly why the seeded demo data (all picks already done) hides the bug. The row must also be in the currently selected warehouse, since $picking is filtered by WarehouseContext::currentId(); pick the same warehouse used at release, or clear the warehouse filter.
- **原因**:Missing inverse relation: `->with('wave')` on a model without `wave(): BelongsTo`.
- **修法**:Add to WarehouseTask: `public function wave(): BelongsTo { return $this->belongsTo(Wave::class); }`. Extend tests/Feature/Warehouse outbound test to GET /warehouse/outbound while a wave has un-confirmed picks.
- **工时**:约 0.5 小时 · 来源:dynamic:harness

### B3 · [Orders] 入库批次 page (nav link) returns 500 when opened without ?ref — undefined array key on the validated array

- **页面**:GET /orders/batches → app/Modules/Orders/Http/Controllers/BatchController.php@index, views/batches/index.blade.php
- **受影响角色**:admin, customer_service, dispatcher, finance, warehouse_supervisor
- **复现**:1. Log in as any of admin / customer_service / dispatcher / finance.
2. Open the 订单 nav and click 入库批次 (equivalently: GET /orders/batches with NO query string).
3. Observed: HTTP 500, ErrorException "Undefined array key \"ref\"" at app/Modules/Orders/Http/Controllers/BatchController.php:15. Expected: the empty lookup page with the reference scan input.
4. Contrast proving the trigger is the absent key, not an empty value: GET /orders/batches?ref= renders 200 with the empty form; GET /orders/batches?ref=COSU6508115030 renders the batch; GET /orders/batches?ref=<41+ chars> correctly returns a validation error, so the rule itself is fine.
5. Note: warehouse_supervisor also 500s on the direct URL (route has no role gate) but does not see the nav link — nav is gated to admin|customer_service|dispatcher|finance at resources/views/layouts/nav/orders.blade.php:5.
6. Regression test to add: actingAs(cs)->get('/orders/batches')->assertOk(); existing tests/Feature/Orders/QueueAndBatchTest.php:113 only exercises the ?ref= case.
- **原因**:Operator precedence: the null-coalesce is applied to the result of the cast, not to the array access.
- **修法**:`$validated = $request->validate([...]); $reference = trim((string) ($validated['ref'] ?? ''));`. Add a test that GETs /orders/batches with no query string.
- **工时**:约 0.25 小时 · 来源:dynamic:harness

## 常见路径失败

### M1 · [Orders] PDF-draft / manually created from_stock order can never be confirmed — 确认 always fails on 'unlinked_stock' and the page offers no way to link an ASN line when the client has no ASN rows

- **页面**:GET /orders/{order} (app/Modules/Orders/views/show.blade.php) + POST /orders/{order}/confirm (OrderController::confirm)
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:Precondition: pick (or have admin create) an active client that has NO rows in asn_lines (i.e. no ASN has ever been raised for it in Warehouse). Log in as customer_service (also reproduces as admin or dispatcher).

Path A — manual order:
1. GET /orders/create. Leave 订单类型 at its default 库存出库 (form.blade.php:47 preselects from_stock), pick the no-ASN client, fill the delivery block and one goods row, submit.
2. You land on /orders/{id}; operational status is 已接收 and the 确认 button is shown.
3. Click 确认 → red flash "库存出库订单仍有货物行未关联 ASN,无法按货物行校验库存。请先从 ASN 生成订单或完成关联。" and the status stays 已接收.
4. Expand 修改此行 on the goods line: the panel contains 中文品名 / 英文品名 / 包装类型 / 箱数 / 重量 + 保存 only. View source — there is no <select name="asn_line_id"> in the DOM at all (show.blade.php:213 skips it because $asnLineOptions is empty). 新增货物行 has no such field either, and PATCH /orders/{order} cannot change 订单类型.
5. Result: the only ways forward are POST /orders/{order}/cancel, or having Warehouse create an ASN for that client first (after which the select appears and the line can be linked, and 确认 then succeeds — confirming the diagnosis).

Path B — PDF draft (the more damaging one): GET /orders/drafts/create, choose the same no-ASN client, upload any .txt/.pdf, submit. DraftOrderService.php:43 forces order_type = from_stock, so the draft banner + 确认 appear and step 3-5 repeat identically. Because the type can never be changed, a PDF purchase order that is actually a pure-transport (提货送货) job can only be abandoned and re-keyed by hand.

Expected: either the asn_line_id control is always rendered on a received from_stock line (empty state "尚无可关联的 ASN 货物行"), or the 确认 button is suppressed/disabled with the reason shown, or the order type can still be switched while 已接收. Actual: an enabled action that the server refuses 100% of the time with no in-page route to the required state.
- **原因**:confirm() enforces the A7 precondition asn_line_id != null for from_stock orders, but the only UI that can satisfy it (the per-line ASN select) is rendered conditionally on the client already having asn_lines rows, and neither the create form nor the add-line form can set asn_line_id. The action is offered in a state the service always refuses, with no path to the required state.
- **修法**:Always render the asn_line_id control on a received from_stock line (show an empty/searchable select with a '尚无可关联的 ASN 货物行' hint instead of hiding it), add the same field to the add-line form, and make confirm() name the offending lines. Optionally allow confirming a from_stock order whose lines are unlinked when the client has no ASNs (fall back to the non-line-level stock check) or offer a '转为提货送货/无库存校验' switch.
- **工时**:约 3 小时 · 来源:static:Orders

### M2 · [Orders] Add-line form silently discards the chosen 包装类型 — every line added to a draft is stored as 'carton'

- **页面**:GET /orders/{order} → 新增货物行 details (app/Modules/Orders/views/show.blade.php:248-256) → POST /orders/{order}/lines
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:Precondition: an order in operational_status = 'received' (未确认草稿). Log in as customer_service (or admin / dispatcher — all three see the control).
1. GET /orders/{order} and scroll to the goods table; expand the 新增货物行 (add line) details block below it.
2. Fill 中文品名 = 木托盘, set 包装类型 = 托盘 (pallet) in the dropdown, 箱数 = 3, leave weight blank.
3. Submit (新增货物行 button → POST /orders/{order}/lines).
Expected: new row shows 包装类型 = 托盘.
Actual: flash 'line saved' appears, but the new row in the goods table shows 包装类型 = 纸箱, and the timeline note is written against the carton line. Same result for satchel/crate/tube/flat_pack/skid — every value collapses to carton.
Confirm at the source: app/Modules/Orders/Http/Controllers/OrderLineController.php:28 — `['package_type' => 'carton'] + array_filter($data, ...)`; PHP's `+` keeps the LEFT key, verified with `php -r '$d=["package_type"=>"pallet"]; var_export(["package_type"=>"carton"] + $d);'` → 'carton'.
4. Knock-on to verify: with that pallet line present, open the order estimate — OrderEstimateService::…:378 matches pallet|plt|skid|托|栈板 on package_type, so the line is costed as 3 cartons instead of 3 pallets.
5. Contrast (proves the add path only): expand 编辑 on the same row, change 包装类型 to 托盘, save (PATCH /orders/{order}/lines/{line}) — this path uses `$line->update($data)` and stores the value correctly. That edit is also the operator workaround.
Fix: `$order->lines()->create(array_filter($data, fn ($v) => $v !== null) + ['package_type' => 'carton']);`
- **原因**:Array-union operator used the wrong way round: defaults on the left win over user input instead of only filling gaps.
- **修法**:Change to `$order->lines()->create(array_filter($data, fn ($v) => $v !== null) + ['package_type' => 'carton']);` so the submitted package type wins and 'carton' is only the fallback.
- **工时**:约 0.5 小时 · 来源:static:Orders

### M3 · [Orders] Choosing a Job that belongs to another client aborts the whole order form with a bare 422 page and loses everything the user typed

- **页面**:GET /orders/create (app/Modules/Orders/views/form.blade.php:35-43) → POST /orders
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:1. Ensure two active clients each own a non-cancelled job (client A with JOB-A001, client B with JOB-B001).
2. Log in as customer_service (also reproducible as admin or dispatcher) and open GET /orders/create.
3. In 客户 pick client A. The Job dropdown still lists "JOB-B001 — 客户B": it contains every client's jobs and does not change when the client select changes (unlike the address-book select just below it).
4. Fill the rest so the form would otherwise validate: 收货联系人, 地址, 城市/郊区, 州, 邮编, 地址类型, 要求日期, 服务级别, plus one goods line (品名, 包装类型, 箱数 1).
5. In the Job dropdown select JOB-B001 (client B's job).
6. Submit (保存).
Observed: bare HTTP 422 error page reading "所选Job不属于所选客户。" (app/Modules/Orders/Http/Controllers/OrderController.php:252). No redirect back, no field-level error on Job, and since abort() flashes no old input, reloading /orders/create shows an empty form — everything typed in steps 4-5 is gone.
Expected: return to the populated form with a validation error on job_id, and/or filter the Job dropdown by the selected client exactly as orders::drafts.create already does (views/drafts/create.blade.php:17,43-54).
- **原因**:A cross-field rule is enforced with abort(422) instead of a validation error, and the job picker is not filtered by the selected client (the address picker is).
- **修法**:Replace abort(422) with `$validator->after()` / an explicit ValidationException on `job_id` so the user returns to the populated form with a field error, and add `data-client-id` + the same JS filter used in orders::drafts.create (drafts/create.blade.php:43-54) to the job select.
- **工时**:约 1 小时 · 来源:static:Orders

### M4 · [Orders] Excel import: Job is mandatory, the list shows only job numbers with no client and is unfiltered — a mismatch throws away the uploaded file with a 422 page

- **页面**:GET /orders/imports/create (app/Modules/Orders/views/imports/create.blade.php) → POST /orders/imports/preview
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:Precondition: at least two active clients (e.g. 客户A and 客户B) each with at least one non-cancelled Job, and a small .xlsx manifest file.
1. Log in as customer_service (also reproducible as admin or dispatcher) and open GET /orders/imports/create.
2. Open the 客户 dropdown and select 客户A.
3. Open the Job dropdown: note every option is a bare job number (e.g. "JOB-20260901-0007") with no client name, and the list contains jobs belonging to 客户B as well — selecting 客户A does not shrink or reorder it (view source: the <option> tags carry no data-client-id and the page contains no <script>; compare /orders/drafts/create, which filters correctly).
4. Pick a job that actually belongs to 客户B (indistinguishable from the UI), fill 日期 (requested_date), leave 服务等级 at any value, attach the .xlsx via 文件, and press 预览.
5. Observed: an unstyled 422 error page showing only "所选Job不属于所选客户。" — the request was aborted with abort_unless() at app/Modules/Orders/Http/Controllers/OrderImportController.php:56, so no input is flashed back and the uploaded file is discarded. The message never appears in the form's @if($errors->any()) block.
6. Press the browser Back button: the file input is empty and must be re-selected, and 服务等级 is reset to the first option (app/Modules/Orders/views/imports/create.blade.php:19 has no @selected(old('service_level'))). Note 日期 does have old() at line 18, but it is irrelevant here because abort() flashes nothing.
Expected: the Job dropdown should show "JOB-… — 客户名" and be filtered to the selected client (as /orders/drafts/create already does), and a mismatch should come back as a redirect-with-errors on job_id rather than a 422 abort page.
- **原因**:Same abort(422)-instead-of-validation pattern, made much more likely by a job picker that hides the owning client and is not narrowed to the chosen client.
- **修法**:Show '{{ $job->job_no }} — {{ $job->client->name }}', add data-client-id + the drafts.create JS filter, turn the abort into a job_id validation error, and add old() to job_id / service_level / requested_date.
- **工时**:约 1.5 小时 · 来源:static:Orders

### M5 · [Orders] Hidden 提货 fieldset still submits its declared-package row: after switching order type back to 库存出库 the form is rejected for a field the user cannot see

- **页面**:GET /orders/create (app/Modules/Orders/views/form.blade.php:128-150 + orders::partials.declared-packages) → POST /orders
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:Log in as customer_service (or admin/dispatcher). GET /orders/create.
A) Blocking-error case: pick 客户 and fill the delivery block normally; set 订单类型 = 提货送货 — the 提货 fieldset appears with one declared-package row (包装类型 defaults to carton). Type 30 into that row's 重量 (kg), leave 件数 empty. Set 订单类型 back to 库存出库 — the fieldset disappears (hidden, not disabled). Fill the goods line (品名 + 件数) and press 提交. Expected: order created. Actual: 422 back with the English message "The declared_packages.0.qty field is required when declared_packages.0.package_type is present." The 件数 field is inside the now-hidden fieldset, so no visible control can fix it; pressing 提交 again reproduces the same error indefinitely because the row is re-rendered from old('declared_packages') inside the hidden fieldset. Only re-selecting 提货送货 and clearing 重量, or reloading and losing all typed data, recovers.
B) Silent-data case (same root cause, no error shown): same steps but fill BOTH 件数 = 2 and 重量 = 30 before switching back to 库存出库. Submit — the order is created successfully. Open GET /orders/{id}: the from_stock order carries an unintended declared package (2 × 30 kg), and that weight feeds TailgateRule, so 尾板 can be auto-flagged on an order whose real goods are light.
- **原因**:The pickup block is hidden with the HTML hidden attribute rather than disabled, so its pre-rendered row keeps being submitted; the prune helper keeps the row because a content field is filled, and the validator then demands a sibling field that is invisible.
- **修法**:Add `pickup.disabled = !pure;` (or set `disabled` on the fieldset's inputs) in toggleType() so nothing inside the hidden block is submitted, and/or drop declared_packages entirely in OrderFormRows::prune when order_type !== 'pickup_deliver'.
- **工时**:约 1 小时 · 来源:static:Orders

### M6 · [Orders] A goods-line row in which only 箱数 was filled is silently deleted before validation — the order is created without it

- **页面**:GET /orders/create (orders::partials.goods-lines) → POST /orders; same partial is used by the portal order form
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:Staff path (multi-row, the main case):
1. Log in as admin (or customer_service / dispatcher) and open GET /orders/create.
2. Fill the header normally: client (active), 订单类型 = from_stock, 收货人/地址/郊区/州/邮编/地址类型, 要求日期, 服务级别.
3. Row 1 of 货物明细: 品名(中文) = 展示架, 包装类型 = carton (default), 箱数 = 10.
4. Click 添加行 (button id="add-goods-line"). In the new row 2 type ONLY 箱数 = 20 — leave 品名(中文) and 品名(英文) empty, leave 包装类型 at its default carton, leave weight/dimensions empty.
5. Submit the form.
Observed: HTTP 302 to /orders/{id}, green flash "订单已创建 …order_no…". /orders/{id} shows exactly ONE goods line, 10 cartons. The 20-carton row is gone; no validation error, no warning, and it is absent from the order timeline. Confirm in the DB (read-only): SELECT COUNT(*) FROM order_lines WHERE order_id = {id}; returns 1.
Expected: a validation error on lines.1.description_cn / lines.1.description_en ("品名必填") and the form re-rendered with both typed rows, exactly as happens today when the same row is submitted through the API payload directly.

Single-row variant that also loses data (pickup_deliver only):
1. Same form, set 订单类型 = pickup_deliver and fill the 提货 fields (pickup name/address/suburb/state/postcode) plus one 申报包装 row.
2. In the single goods row clear 品名 and enter only 箱数 = 15. Submit.
Observed: order created with zero goods lines and no error, because 'lines' is nullable for pickup_deliver.
(For from_stock / delivery orders a single carton-only row IS caught — lines prunes to null and required_unless fires — so do not use that case as the repro.)

Portal path (same defect, same partial):
Log in as a client portal user, POST the equivalent form at /portal/orders/create with row 1 complete and row 2 carrying only 箱数; PortalOrderController.php:95 prunes identically. The same happens on the portal order update route (PortalOrderController.php:122).

Root cause to verify while testing: app/Modules/Orders/Http/OrderFormRows.php:14 excludes carton_qty (and package_type) from LINE_CONTENT, and OrderController.php:91 / PortalOrderController.php:95 call prune() before validation. Contrast with PACKAGE_CONTENT on OrderFormRows.php:16, which includes 'qty' — a qty-only 申报包装 row is correctly kept and rejected with an error.
- **原因**:To ignore the spare row whose carton_qty is pre-filled with 1 (goods-lines.blade.php:7 default `['package_type' => 'carton', 'carton_qty' => 1]`), the pruner treats ANY carton_qty as non-content, so a user-typed quantity is discarded instead of producing the 'description required' error.
- **修法**:Keep a row when carton_qty differs from the rendered default (or simply include carton_qty in LINE_CONTENT and stop pre-filling 1 in goods-lines.blade.php:7), so an incomplete row raises a validation error instead of vanishing.
- **工时**:约 1 小时 · 来源:static:Orders

### M7 · [Orders] No zh validation translations: every rejected order form prints English messages with raw array field names (lines.0.carton_qty, declared_packages.0.qty)

- **页面**:GET /orders/create, /orders/imports/create, /orders/drafts/create, /orders/addresses/create, /orders/{order} (all POST/PATCH handlers)
- **受影响角色**:admin, customer_service, dispatcher, finance, warehouse_supervisor
- **复现**:Login as customer_service (or admin). GET /orders/create.

Do NOT use the first goods line — its 箱数 input is pre-seeded with 1 and carries the HTML required attribute (goods-line-row.blade.php:14 @required($first)), so the browser blocks the submit with its own native bubble and the server is never reached.

1. Fill the required header fields (客户 / Job / order_type = e.g. delivery, 送货地址 fields) so the form is otherwise valid.
2. Leave the first goods line as rendered (箱数 = 1).
3. Click 添加货物行 to add a second row. In that second row type only a 中文品名 (e.g. 纸箱样品) and leave 箱数 empty. The second row has no required attribute, so the browser submits it, and OrderFormRows::prune keeps it because description_cn is in LINE_CONTENT while carton_qty is not.
4. Submit 建立订单.

Expected (zh product): a Chinese message naming 箱数 and the row.
Actual: the error box renders the translated heading 请修正以下内容： followed by the English framework default "The lines.1.carton qty field is required." — an English sentence naming a 0-based array key that appears nowhere on the Chinese page.

Second, independent case on the same page: in the 申报包装 (declared_packages) table, add a row and enter only 重量 (weight_kg), leaving 件数 (qty) empty. The row survives prune (weight_kg is in PACKAGE_CONTENT) and the package-type select always submits its default, so required_with fires: "The declared_packages.0.qty field is required when declared_packages.0.package type is present."

Third: on /orders/create pick order_type = from_stock and clear every goods line (the remove button clears the last row's inputs, so prune nulls the whole array) → "The lines field is required unless order type is in pickup_deliver."

Extra observation worth reporting: because prune re-indexes kept rows with array_values(), if a spare row above the offending one is dropped, the index printed in the message does not match the visible row position on the redisplayed form.
- **原因**:The project ships no validation language file and no `attributes` map, so every validation failure in the module is rendered by Laravel's English defaults with dotted attribute names.
- **修法**:Add lang/zh/validation.php (translated messages + an `attributes` map covering lines.*.carton_qty, lines.*.description_cn/en, declared_packages.*.qty, pickup_*, deliver_to_*), and set the app locale/fallback so it is used.
- **工时**:约 3 小时 · 来源:static:Orders

### M8 · [Orders] Order API usage example printed on the token page omits package_type, and the API accepts it as null — the documented request dies with a 500

- **页面**:GET /orders/api-tokens (app/Modules/Orders/views/api-tokens/index.blade.php:46-51) → POST /orders/api/orders
- **受影响角色**:admin
- **复现**:1. Log in as admin and open GET /orders/api-tokens. Issue a token for any client (pick a client, give the token a name, submit) and copy the one-time plain token shown in the flash. 2. Scroll to the "usage" block at the bottom of the same page and copy the example JSON body verbatim: {"order_type":"from_stock","external_ref":"PO-1001","deliver_to_name":"Test","deliver_to_address":"1 Test St","deliver_to_suburb":"Melbourne","deliver_to_state":"VIC","deliver_to_postcode":"3000","requested_date":"2026-10-01","lines":[{"description_en":"Widget","carton_qty":10,"asn_line_id":null}]} — note it contains no package_type. 3. POST it to /orders/api/orders with headers: Authorization: Bearer <token>, Content-Type: application/json, Idempotency-Key: test-key-1 (external_ref must be unique per client, so bump PO-1001 on each attempt). 4. Expected: 201 with the created order, or a 422 naming the missing field. Actual: HTTP 500 "Server Error" (HTML, no JSON error body). The log shows SQLSTATE[HY000]: General error: 1364 Field 'package_type' doesn't have a default value on the insert into order_lines. 5. Repeat the exact same request with the same Idempotency-Key: it 500s again rather than replaying, because the transaction rollback means no order_api_idempotency_keys row was ever written. 6. Confirm the cause by resending the identical body with "package_type":"carton" added to the line — it returns 201, proving only the omitted field breaks it. Sending "package_type":null also 500s (validation is nullable, the column is NOT NULL).
- **原因**:The API rule allows a missing/null package_type while the column is NOT NULL without a default and the creation service applies no fallback, so a QueryException escapes as a 500 instead of a 422.
- **修法**:Default it in OrderCreationService (`'package_type' => $line['package_type'] ?? 'carton'`) or make the API rule `['required', Rule::in(OrderEnums::PACKAGE_TYPES)]` and add package_type to the example body on the token page.
- **工时**:约 0.5 小时 · 来源:static:Orders

### M9 · [Portal] Hidden 提货包裹 rows are still submitted: one leftover value permanently blocks 获取估价 / 确认提交 with an error about a field the client cannot see

- **页面**:POST /portal/orders/preview and POST /portal/orders — app/Modules/Portal/views/orders/create.blade.php (form at :14, fieldset at :77) + app/Modules/Orders/views/partials/declared-packages.blade.php
- **受影响角色**:client
- **复现**:Preconditions: sign in as a client-role user (user->isClientUser() with client_id set). Go to /portal/orders/create.
1. Set 订单类型 = 纯运输提货配送 (pickup_deliver). The 提货信息 fieldset (#pickup-fields) becomes visible with the 包裹 table.
2. In the first 包裹 row, type only 重量 = 25 (leave 数量 empty; leave 包装类型 at its default 纸箱/carton).
3. Set 订单类型 back to 从库存出货 (from_stock). The whole fieldset disappears (hidden attribute only — the inputs stay in the DOM and remain submittable; confirm in devtools that #pickup-fields has no `disabled`).
4. Fill the visible required fields: 收货人, 地址, 郊区, 州, 邮编, 要求日期 (today or later), 服务等级; and one goods line with 包装类型=纸箱, 箱数=5, 中文品名=纸箱货.
5. Click 获取估价 (POST /portal/orders/preview).
Expected: the estimate renders — the hidden pickup packages should be irrelevant to a from_stock order.
Actual: validation fails with "The declared packages.0.qty field is required when declared packages.0.package type is present." No 包裹 table is on screen, so the named field is invisible. Because the page re-renders declared_packages from old(), the stale 重量=25 row returns and every subsequent 获取估价 / 确认提交订单 click fails identically.
Escape (workaround, not obvious to a tester): switch 订单类型 back to 纯运输提货配送, clear the 重量 cell (or click the × on that row), then switch back to 从库存出货.
Also reproducible on POST /portal/orders (确认提交订单) and on the staff form /orders/create, which shares the same fieldset pattern (app/Modules/Orders/views/form.blade.php:128, 198-199).
- **原因**:The pickup block is hidden with the HTML `hidden` attribute instead of `disabled`, so its inputs are still serialised. OrderFormRows::prune only drops a declared-package row when *every* content field is empty, so a single leftover 重量 keeps a row whose defaulted package_type then makes qty required — for a row the client cannot see, and which old() faithfully re-renders on each failed attempt.
- **修法**:Bind `disabled` as well as `hidden` on #pickup-fields in toggleType() (a disabled fieldset's controls are never submitted, and the browser skips their constraint validation too). Belt-and-braces on the server: in PortalOrderController::store/preview, before validation, drop `declared_packages` when order_type !== 'pickup_deliver' and drop `lines` when order_type === 'pickup_deliver'. Same change is needed in the staff form which shares the partial.
- **工时**:约 1.5 小时 · 来源:static:Portal

### M10 · [Portal] 申请退货 is offered on 纯运输 (pickup_deliver) orders but has no quantity rows, so the request can never be submitted

- **页面**:POST /portal/orders/{order}/returns — app/Modules/Portal/views/orders/show.blade.php:182-197
- **受影响角色**:client
- **复现**:Preconditions: a client portal user, and one 纯运输提货配送 order for that client created via /portal/orders/create with 订单类型 = 纯运输提货配送 (declared packages only, zero 货物明细 lines), advanced by staff to 已确认 and then 已发运 (OrderStatusService allows confirmed → dispatched directly for pickup_deliver) or 已送达 via POD.
1. Sign in to the portal as the client user and open /portal/orders/{id} for that order.
2. Scroll to the bottom and expand 申请退货. Observe the panel contains only the 原因 text box and the 提交退货申请 button — no 退回箱数 number input for any line, because the goods table's rows come from declaredPackages while the return form iterates $order->lines (empty). Confirm with view-source: there is no input named quantities[...] in the form.
3. Type any reason (e.g. 破损) and click 提交退货申请 (POST /portal/orders/{id}/returns).
Expected: either the 申请退货 panel is not offered for a line-less order, or the form lets the client select declared packages to return.
Actual: the page redisplays with the untranslated English validation error "The quantities field is required." (no lang/zh/validation.php exists), and the request can never succeed from the UI — there is no quantities control to fill. A hand-crafted POST with quantities[]=1 is also rejected by ReturnRequestService with orders.returns.messages.no_lines.
Also verify the staff-side twin: sign in as a coordinator, open /orders/{same id}, expand 退货申请 — the same form renders with no quantity rows and POST /orders/{id}/returns fails the same way, so there is no internal workaround.
- **原因**:canRequestReturn is derived from order type + shipped status only, while the form body is driven by $order->lines. Pure-transport orders satisfy the first and have none of the second, so the UI renders an action the server can never accept. Note the same view already knows declared packages exist — it lists them in the goods table at line 67-69.
- **修法**:Either gate the block on `$canRequestReturn && $order->lines->isNotEmpty()` and show 请联系客服 for line-less orders, or (better) render the quantity rows from declaredPackages for pickup_deliver orders and teach ReturnRequestService::request to build return lines from declared packages. Cheapest correct fix is the gate; pass `'canRequestReturn' => $order->acceptsReturnRequest() && $order->lines->isNotEmpty()` from PortalOrderController::show:195.
- **工时**:约 3 小时 · 来源:static:Portal

### M11 · [Transport] 打印运单 button on a manual-carrier shipment always dead-ends on a 422 page — the manual adapter cannot produce labels

- **页面**:GET /transport/{shipment} → GET /transport/{shipment}/label — views/shipments/show.blade.php:107-112, ShipmentLabelController
- **受影响角色**:admin, customer_service, dispatcher, transport_operator
- **复现**:Setup (dispatcher, admin, customer_service or transport_operator — the button is not role-gated in the view):

A. Manual carrier, fully booked (main case)
1. Open an outbound shipment in 运输 and request quotes; when no live carrier gateway is configured, use 人工报价 with the seeded MANUAL carrier service. Record a FINAL manual quote (ManualQuoteService, quote_stage=final).
2. Select that final quote (QuoteSelectionService) and book it with a booking reference, e.g. MANUAL-SHP-0001 (ShipmentBookingService). The seeded demo shipments C and the "Manual Transport Exception" shipment in DemoFlowSeeder.php:342-346 / 424-428 are already in this state.
3. Open GET /transport/{shipment}. The 打印运单 button is shown (blade only checks selectedQuote !== null).
4. Click it → GET /transport/{shipment}/label returns a full-page HTTP 422 with 「承运商 Waybill 尚不可用。」 (transport.labels.waybill_unavailable). No inline error, no way back except the browser Back button. This is permanent for manual shipments: ManualCarrierAdapter::capabilities()['label'] === false, and waybill_document_id is only ever written by ShipmentLabelService itself, so the stored-document branch can never apply.

B. Preliminary quote selected (second case, any source incl. own_fleet)
1. On a quoted outbound shipment, select a PRELIMINARY quote. QuoteSelectionService.php:74-78 saves selected_quote_id and returns before confirmation.
2. Reload GET /transport/{shipment} → 打印运单 / 打印自有车队标签 button appears.
3. Click it → 422 page 「尚未选定最终报价。」 (transport.labels.no_selected_quote), because ShipmentLabelService.php:41-45 demands quote_stage='final' AND status='selected'.

Expected: the button should be hidden (or disabled with an explanation) when the label cannot be produced, and any remaining failure should be an inline flash/validation error on the shipment page rather than abort(422).

Not affected: own_fleet with a confirmed final selected quote — that branch renders the PDF correctly, so the feature is not universally broken.
- **原因**:The Blade condition (selectedQuote !== null) is far weaker than the service precondition (final + selected + booking_ref + adapter label capability). The manual and own-fleet adapters are the only configured sources, and manual declares label=false, so the button is permanently broken for the module's primary quoting path.
- **修法**:Render the label link only when it can succeed: `@if ($shipment->selectedQuote?->quote_stage === 'final' && $shipment->selectedQuote->status === 'selected' && ($shipment->selectedQuote->source === 'own_fleet' || ($shipment->waybill_document_id !== null || (in_array($shipment->selectedQuote->source, ['transdirect','karrio'], true) && $shipment->booking_ref))))`. Also change ShipmentLabelController to `return back()->withErrors(...)` instead of abort(422) so the user stays on the page.
- **工时**:约 2 小时 · 来源:static:Transport

### M12 · [Billing] 未开票池「按此 Job 开票」on a storage-only Job always fails (row shows lines + amount, service always throws)

- **页面**:GET /billing/unbilled → app/Modules/Billing/views/invoices/unbilled.blade.php (POST /billing/invoices/job/{job})
- **受影响角色**:admin, finance
- **复现**:Prereq (any environment with weekly storage charges): a Job that has unbilled storage charges and no unbilled non-storage charges. Reach it either by running billing:storage-weekly / the DemoFlowSeeder step "Daily snapshots for the last 8 days and weekly storage billing" and then invoicing that job's service charges, or simply by picking a job that only carries storage rows.
1. Log in as finance (or admin) and open GET /billing/unbilled.
2. Find the client card for that job; the Job row shows a non-zero line count and money amount (e.g. "3 行 / $150.00") — these totals come from InvoiceController::unbilled() line 27-28, which sums every unbilled charge including category='storage'.
3. Click 按此 Job 开票 (POST /billing/invoices/job/{job}).
Expected: an invoice draft, or no button at all on a row that cannot be invoiced per-job.
Actual: redirected back to /billing/unbilled with a red untranslated English banner "No unbilled charges on this Job." (InvoiceService.php:34-37 filters category != 'storage', then throws; tryDraft at InvoiceController.php:144-153 surfaces the raw exception string via layouts/partials/flash.blade.php). The row is unchanged and still shows 3 行 / $150.00, and the button will fail identically every time.
4. Secondary, cosmetic: on a mixed job (say $500 total of which $150 storage) the same button drafts an invoice of only $350 while the row advertised $500 — the storage remains in the pool by design, but the row total never matches what per-job invoicing will produce.
Workaround that does work (same page footer): the 仓储费 form (rendered when the client has any storage charge) or the 按账期 form with scope=仓储费.
- **原因**:The view/controller and the service disagree about what «per-Job invoicing» covers: the pool aggregates all unbilled charges per job, while draftForJob() filters out category='storage' and throws when nothing is left. The page offers an action whose precondition it never checks and gives the user no way to reach a valid state from that row.
- **修法**:Split the per-job aggregation in InvoiceController::unbilled() into service vs storage (e.g. 'service_amount_cents' / 'service_count' and 'storage_amount_cents'), render the 按此 Job 开票 button only when service_count > 0, show the storage part as a separate muted figure pointing at the 仓储费 / 按账期 (scope=仓储费) forms, and translate the InvalidArgumentException into a zh flash message as a belt-and-braces guard.
- **工时**:约 2 小时 · 来源:static:Billing

### M13 · [Billing] Quote lines with a charge code but no qty are silently discarded, and a quote cannot be edited afterwards

- **页面**:GET /billing/quotes/create → app/Modules/Billing/views/quotes/create.blade.php (POST /billing/quotes)
- **受影响角色**:admin, finance, customer_service
- **复现**:1. Log in as a customer_service user (also reproducible as admin or finance). 2. Go to GET /billing/quotes/create (报价 → 新建报价); three line rows are visible. 3. Client: pick any active client; Stage: leave 'preliminary'. 4. Row 1: charge code TR-DELIVERY-BASE, 数量 = 1. 5. Row 2: charge code TR-TAILGATE, leave 数量 EMPTY, fill 成本 = 50 (cost/weight are irrelevant — leaving all three number fields empty reproduces it too). 6. Submit. EXPECTED: a validation error that qty is required for row 2, form re-rendered with input preserved. ACTUAL: HTTP 302 to /billing/quotes/{id}; the quote is created with ONLY the TR-DELIVERY-BASE line; the TR-TAILGATE line is absent, subtotal/GST/total reflect only line 1, and there is no error, warning or flash message about the dropped row. 7. Variant: repeat with 数量 = 0 explicitly on row 2 — same silent drop. 8. Confirm no recovery path: /billing/quotes/{id} has no edit control and no PUT/PATCH/edit route exists (routes.php declares only quotes.index, quotes.create, quotes.store, quotes.show, quotes.status), so the only fix is creating a fresh quote and setting the wrong one to 'rejected' via POST /billing/quotes/{id}/status. 9. Negative control proving the filter is the cause: submit with charge codes chosen but qty empty on ALL rows — you then DO get the 'no lines' error, showing the code only complains when every row is filtered out.
- **原因**:The filter that exists to drop the empty pre-rendered rows also drops half-filled real rows: 'charge code chosen but qty missing' is treated as 'empty row' instead of as a validation error, and the form gives the field no required marker.
- **修法**:Make qty conditionally required on the server — 'lines.*.qty' => ['required_with:lines.*.charge_code','numeric','gt:0'] — and keep the filter only for rows where charge_code is empty; add the required attribute in the Blade when a code is picked (or a small JS toggle), and add zh attribute names so the message is readable.
- **工时**:约 1.5 小时 · 来源:static:Billing

### M14 · [Transport] Shipment page offers booking / manual quote / quote select / redelivery / carrier POD / extra-charge forms to finance (and own-fleet cost to customer_service) — server refuses all of them with 403

- **页面**:GET /transport/{shipment} → app/Modules/Transport/views/shipments/show.blade.php (forms at lines 27, 59, 88, 117, 132, 173, 249); controllers ShipmentBookingController, ManualQuoteController, QuoteSelectionController, RedeliveryController, CarrierPodController, ExtraChargeController, OwnFleetCostController
- **受影响角色**:finance, customer_service
- **复现**:Precondition: seeded demo data with at least one transport shipment (any state).

A) finance, guaranteed hit (no state needed):
1. Log in as finance@erp.local.
2. The 运输 (Transport) dropdown is visible — resources/views/layouts/nav/transport.blade.php has no @role guard, unlike nav/orders and nav/warehouse.
3. Click 运输 → the shipment list renders (IndexController has no role check).
4. Click any shipment number → GET /transport/{id} renders (ShipmentController::show has no role check).
5. Expand 额外费用 (extra charges) — this <details>/<form> is rendered unconditionally, no state and no role condition. Pick any charge type, qty 1, any uom, fill the required note, submit.
6. POST /transport/{id}/extra-charges → 403 (ExtraChargeController allows only admin|customer_service|dispatcher|transport_operator, and it uses raw abort_unless so the 403 page cannot even list the allowed roles).

B) finance, state-dependent forms on the same page — same 403:
- shipment status quote_confirmed with a selected quote → 预订 form → POST /transport/{id}/book → 403
- status quoting|quoted (and manual carrier services exist) → 手工报价 → POST /transport/{id}/quotes/manual → 403
- status quoted with a live quote row → 选择报价/确认 button → POST /transport/{id}/quotes/{quote}/select → 403
- status failed → 重新派送 → POST /transport/{id}/redelivery → 403
- selected quote source != own_fleet and no delivered POD → 承运商 POD upload → POST /transport/{id}/pod → 403

C) customer_service, own-fleet cost:
1. Log in as customer_service@erp.local, open a shipment whose selected quote source is own_fleet and whose status is booked/dispatched/in_transit/delivered/failed.
2. Expand 录入自有车队成本, enter cost + note, submit.
3. POST /transport/{id}/own-fleet-cost → 403 (OwnFleetCostController requires admin|dispatcher|transport_operator|finance). Note: finance IS allowed on this one — this specific form is only broken for customer_service.

Expected: forms the user cannot submit are not rendered (or are rendered read-only), matching the convention used in app/Modules/Orders/views/show.blade.php:137,167 and app/Modules/Warehouse/views/asns/show.blade.php:23.
Actual: every action form in app/Modules/Transport/views/shipments/show.blade.php is rendered on shipment state alone; grep for @role/hasAnyRole across app/Modules/Transport/views/ returns zero matches.
- **原因**:Role checks live only in the controllers; the Blade view renders every action form purely on shipment state.
- **修法**:Guard each form with the same role list as its controller (e.g. `@role('admin|customer_service|dispatcher|transport_operator')` around book/manual/select/redelivery/pod/extra-charges; `@role('admin|dispatcher|transport_operator|finance')` around own-fleet cost), ideally via constants on the services so view and controller cannot drift. Convert the three `abort_unless(...,403)` to `RequiredRoles::requireAny` so the 403 page can list the allowed roles.
- **工时**:约 1.5 小时 · 来源:dynamic:harness

### M15 · [Transport] 手工报价 form is offered on shipments that have no address/package data yet, so recording a quote always fails

- **页面**:GET /transport/{shipment} (status quoting/quoted) → views/shipments/show.blade.php lines 56-83; POST transport.shipments.quotes.manual → ManualQuoteService::record
- **受影响角色**:dispatcher, transport_operator, customer_service, admin
- **复现**:Precondition: an order with order_type 'from_stock' (or 'return') that has been confirmed but whose outbound fulfilment has not yet been packed, plus at least one active carrier_service with source='manual'.

1. Log in as any of admin / dispatcher / transport_operator / customer_service.
2. Confirm a from_stock order (or pick an existing one). ShipmentIntakeService::fromConfirmedOrder creates the preliminary shipment with fulfilment_id = NULL and status 'quoting'; an exception of type 'manual_transport' is raised in the exception queue, instructing a human to quote manually.
3. Go to GET /transport, click that shipment number (GET /transport/{shipment}). Verify the 手工报价 (manual quote) panel is rendered.
4. Fill 承运商服务 = any manual service, 报价阶段 = final (the default) OR preliminary — both fail identically, 成本 = 10000, 客户价 = 15000, ETA = 3. Submit (POST /transport/{shipment}/quotes/manual).

Expected: the quote is recorded, or the form is not offered / a zh hint explains the order must be packed first.
Actual: redirected back with errors[manual_quote] = '预订所需的地址或包裹资料不完整。' and no transport_quotes row created. Repeats indefinitely; the only way through is to wait for the warehouse to pack the fulfilment (which sets shipments.fulfilment_id), after which the same submission succeeds.

Contrast case proving it is state-dependent, not a global outage: repeat on a pickup_deliver order's shipment (sender comes from orders.pickup_address, declared_packages are required_if) — the identical submission succeeds.

Key file/line anchors: app/Modules/Transport/views/shipments/show.blade.php:56; app/Modules/Transport/Http/Controllers/ShipmentController.php:21; app/Modules/Transport/Services/ManualQuoteService.php:45-48; app/Modules/Transport/Services/ShipmentQuoteRequestFactory.php:71-86 (sender via fulfilment_id) and 105-130 (items falls back to declared_packages, so packing measurements are NOT the trigger); app/Modules/Transport/Services/ShipmentIntakeService.php:47 (fulfilment_id => null); app/Modules/Transport/Services/TransportOptionService.php:44-47 and 298-310 (manual_transport exception fallback that the manual form cannot satisfy); lang/zh/transport.php:179.
- **原因**:The view does not check the same precondition (buildable booking request) that the service enforces.
- **修法**:Compute `$canQuote = app(BookingRequestBuilder)->build($shipment, 'final') !== null` in ShipmentController@show and either hide the form or render the zh hint explaining that the order must be packed/measured first.
- **工时**:约 0.5 小时 · 来源:dynamic:harness

## 提示 / 体验问题

### M1 · [Warehouse] Stocktake 记录 (count) button is rendered for dispatcher / customer_service / finance, whose POST the route always refuses with 403

- **页面**:GET /warehouse/stocktakes/{stocktake} — app/Modules/Warehouse/views/stocktakes/show.blade.php
- **受影响角色**:dispatcher, customer_service, finance
- **复现**:Prerequisite: a stocktake in 盘点中 (counting) status — as warehouse_supervisor create one via 仓库 → 盘点 → 新建盘点, note its id, do NOT close it.
1. Sign in as finance (repeat for dispatcher and customer_service).
2. Note there is no 盘点 entry in the Warehouse nav for this role; navigate directly to /warehouse/stocktakes (the GET route is open to the role) and click the stocktake, or go straight to /warehouse/stocktakes/{id}.
3. Observe: the top 扫描 box is correctly absent and the bottom 关闭盘点 button is absent, but every line row shows an editable 数量 input (prefilled with expected_qty), a 原因 input and a 记录 button.
4. Click 记录 on any line → POST /warehouse/stocktakes/{id}/lines/{lineId} returns HTTP 403 "User does not have the right roles." Reproducible on every line, every attempt.
Negative control: open a CLOSED stocktake as the same user — only read-only 已数/差异/原因 cells render, no button. This is why the 盘点差异 exception deep link in Platform (which only ever exists after close) does not surface the bug; URL access to a counting stocktake is the only path.
- **原因**:Blade guard omitted on one of the three action forms of the page; the surrounding page is visible to three more roles than the POST route accepts, so the @role guard that the sibling forms carry is load-bearing and its absence turns the button into a guaranteed 403.
- **修法**:Wrap the per-line count form in `@role('admin|warehouse_supervisor|warehouse_operator') ... @endrole` (falling back to the read-only 已数/差异/原因 cells for other roles), i.e. move the existing `@if ($stocktake->status === 'counting')` condition to `@if ($stocktake->status === 'counting' && auth()->user()->hasAnyRole(['admin','warehouse_supervisor','warehouse_operator']))` or nest the @role exactly as done on line 13.
- **工时**:约 0.25 小时 · 来源:static:Warehouse

### M2 · [Warehouse] 入库单 links on the 预报单 page (and in global search) 403 for dispatcher, who is allowed on the ASN page but not on the receipts routes

- **页面**:GET /warehouse/asns/{asn} → app/Modules/Warehouse/views/receipts/_batches.blade.php (shared partial, also included by receipts/show)
- **受影响角色**:dispatcher
- **复现**:Setup: a user whose only role is `dispatcher`; an ASN that has at least one goods receipt (status arrived/receiving/received — any ASN where 收货 has been performed at least once).

1. Sign in as the dispatcher-only user.
2. Navigate to 预报单 (GET /warehouse/asns) — loads 200 (route group at app/Modules/Warehouse/routes.php:22 includes dispatcher).
3. Open the received ASN (GET /warehouse/asns/{asn}) — loads 200.
4. Scroll to the 入库单 heading. The batches table renders with the receipt number as a hyperlink and, in the 操作 column, 查看 and PDF links. (The 入库完成 button is correctly absent — it is @role-guarded at receipts/_batches.blade.php:21.)
5. Click the receipt number → GET /warehouse/receipts/{receipt} → HTTP 403 "User does not have the right roles." Same for 查看 (same URL) and for PDF → GET /warehouse/receipts/{receipt}/pdf → 403.

Second path (global search):
6. Go to /admin/search (dispatcher is in $staff, app/Modules/Platform/routes.php:17).
7. Type the receipt number (e.g. the receipt_no seen in step 4) and submit.
8. A 仓储 result of type 入库单 appears with the receipt_no as label and its status as meta; its href is warehouse.receipts.show. Click it → 403.

Control: repeat step 3-5 as `warehouse_operator` or `finance` — the receipt page and PDF load 200, confirming the 403 is role-scoped and not a data problem.

Expected: the dispatcher should either not be offered the links (render receipt_no as plain text, omit 查看/PDF) and not get goods_receipt hits in search, or be granted read access — but the nav already excludes dispatcher from 入库单 (resources/views/layouts/nav/warehouse.blade.php:11), so suppressing the links is the consistent fix.
- **原因**:The role sets of the two route groups differ by one role (dispatcher), but the shared partial that bridges them renders its links unconditionally; the 入库完成 button inside the same partial *is* guarded (line 21), so the guard was simply not extended to the navigation links.
- **修法**:Either add dispatcher to the receipts route group at routes.php:46 (dispatcher is already trusted with all other warehouse read pages, incl. stock and ASNs — this is the smaller, more consistent change), or wrap the three links in `@role('admin|warehouse_supervisor|warehouse_operator|customer_service|finance')` and render the receipt number as plain text otherwise. Also filter the goods_receipt branch of the search registration in WarehouseServiceProvider.php:41 by the same roles.
- **工时**:约 0.5 小时 · 来源:static:Warehouse

### M3 · [Warehouse] 收货 is impossible in any warehouse that has no active 收货区 location: the required 库位 select renders with zero options and the browser silently blocks submit

- **页面**:GET /warehouse/asns/{asn}/lines/{line}/receive and GET /warehouse/receiving/unplanned — receiving/form.blade.php:14, receiving/unplanned.blade.php:31-37
- **受影响角色**:admin, warehouse_supervisor, warehouse_operator
- **复现**:Preconditions: log in as warehouse_supervisor (admin also works). Data as seeded: only MEL has locations.

1. Navigate by typing the URL /warehouse/config/locations (there is no menu link — confirmed by grep: route('warehouse.locations.index') appears nowhere in any view).
2. In the top 仓库 form create a warehouse: code SYD, name Sydney DC. Submit → flash confirms creation. Do NOT add any location for SYD (this is the realistic slip: an operator creates the warehouse first and configures 库位 later).
3. Go to /warehouse/asns/create, choose client, choose warehouse SYD, create the ASN, then add one line (any mark/description/expected cartons).
4. On the ASN page press 收货 for that line → GET /warehouse/asns/{asn}/lines/{line}/receive. Observe: the 库位 dropdown renders with zero options (ReceivingController::form scopes locations to the ASN warehouse + type=receiving + active).
5. Fill 实收箱数 and press 提交收货. Expected: an actionable message. Actual: Chrome shows the native bubble "Please select an item in the list" on the empty select and no POST is made; the line cannot be received at all. (If the client-side check is bypassed, the server returns the same block via the `required` + exists rule.)
6. Now open /warehouse/receiving/unplanned. The 库位 select initially shows MEL's RCV location. Change 仓库 to SYD. Observe: every 库位 option becomes hidden+disabled and the box appears empty while MEL's option is still internally selected. Fill client + one goods row and press 提交. Actual: the form posts WITHOUT receiving_location_id (a selected-but-disabled option is not submitted) and comes back with the "收货库位 is required"-style validation error; no receipt is created.
7. Recovery (shows this is minor): back on /warehouse/config/locations, add a location for SYD with type 收货区 (e.g. zone RCV / aisle 01 / bin 01) — both screens then work normally.
- **原因**:A `required` <select> whose option list is data-driven and can be empty: the browser blocks the submit with no actionable message, and the remediation page (库位配置) has no navigation entry, so the user has no in-app route to the required state. Same pattern on the 无预报收货 screen via the JS warehouse→location filter.
- **修法**:(1) Add a 库位配置 entry to resources/views/layouts/nav/warehouse.blade.php under the `admin|warehouse_supervisor|warehouse_operator` block pointing at route('warehouse.locations.index'). (2) In receiving/form.blade.php and receiving/unplanned.blade.php, when the location list for the chosen warehouse is empty, replace the select+submit with a translated notice linking to 库位配置 (new zh key, e.g. warehouse.receiving.no_receiving_location). (3) In the unplanned JS, when firstVisible is null, disable the submit button and show the same notice rather than leaving a foreign location selected.
- **工时**:约 2 小时 · 来源:static:Warehouse

### M4 · [Warehouse] 'units' => required_if:received_cartons,>,0 is not valid required_if syntax — the rule fires on received_cartons = 0 and never on a positive count

- **页面**:POST /warehouse/asns/{asn}/lines/{line}/receive — app/Modules/Warehouse/Http/Controllers/ReceivingController.php:70
- **受影响角色**:admin, warehouse_supervisor, warehouse_operator
- **复现**:Prereq: sign in as warehouse_operator (or admin/warehouse_supervisor); pick an ASN in status booked/arrived/receiving with an unreceived line (待收货 worklist at GET /warehouse/receiving) and note {asn} and {line}; open GET /warehouse/asns/{asn}/lines/{line}/receive and copy a valid receiving_location_id from the 收货库位 dropdown plus the CSRF token.

A) False negative (the damaging half) — with the same session cookie and CSRF token, POST /warehouse/asns/{asn}/lines/{line}/receive with ONLY: _token, receiving_location_id=<id>, received_cartons=10 (deliberately send no units[...] keys at all).
Expected: 422 / redirect back with "单位" required error.
Actual: 302 to the ASN page with the 收货成功 flash. Verify in the DB (read-only): asn_lines.received_cartons = 10 for that line, but SELECT COUNT(*) FROM stock_units WHERE asn_line_id = {line} returns 0 and there are no stock_ledger receipt rows — 10 cartons booked on the line and into the 入库单 with nothing in stock. (Sending units[]= as an empty array instead of omitting it gives the same result.)

B) False positive (inverse half, harmless today) — repeat the POST with received_cartons=0 and no units[]: validation now REJECTS with the units-required error, i.e. the rule fires only in the case where nothing was received. From the browser this never surfaces because app/Modules/Warehouse/views/receiving/form.blade.php always posts three units[] rows (rows 1-2 use the HTML `hidden` attribute, which is still submitted), so the array is always present.

Note for the tester: the browser form itself cannot reach case A; keep this fix together with the hidden-empty-rows fix on units.*.carton_qty so the two rules stay consistent.
- **原因**:Laravel's `required_if:field,value,...` compares the other field for equality against the listed values; it has no comparison operators. As written the rule means 'required when received_cartons equals ">" or equals 0', which is the exact inverse of the documented intent ('units are required when something was received').
- **修法**:Replace with a closure or an `after` hook: `'units' => ['array', function ($attr, $value, $fail) use ($request) { if ((int) $request->input('received_cartons') > 0 && empty(array_filter((array) $value, fn ($u) => (int) ($u['carton_qty'] ?? 0) > 0))) { $fail(__('warehouse.receiving.units_required')); } }]`, and add the zh string. Do this together with the fix for the hidden-rows blocker so the two rules stay consistent.
- **工时**:约 0.25 小时 · 来源:static:Warehouse

### M5 · [Warehouse] 新建仓库 form loses all six typed fields after a validation error (no old() anywhere on the 库位配置 page)

- **页面**:POST /warehouse/config/warehouses — app/Modules/Warehouse/views/locations/index.blade.php:8-17
- **受影响角色**:admin, warehouse_supervisor
- **复现**:Log in as admin (or warehouse_supervisor). Go to GET /warehouse/config/locations.

A) Warehouse form (top, 新建仓库):
1. In 代码 type a code that already exists, e.g. MEL (any existing warehouses.code shown in the headings further down the page).
2. Fill 名称 = "Melbourne DC 2", 地址 = "1 Test St", 城市/suburb = "Laverton", 州 = "VIC", 邮编 = "3026".
3. Press 新建仓库.
Expected: page returns with the error banner "The code has already been taken." AND all six values still in their inputs.
Actual: the error banner shows, but all six inputs are empty — code, name, address, suburb, state, postcode must all be retyped. (Same outcome with an invalid-but-unique code such as "MEL 2", which fails alpha_dash.)

B) Location form (second form, 新建库位) — same page:
1. Pick any 仓库 other than the first option in the select.
2. Type 区 = "A-1" (hyphen fails the alpha_num rule), 巷 = "01", 位 = "05", and change 类型 away from storage (e.g. to a non-default type).
3. Press 新建库位.
Expected: error banner plus retained zone/aisle/bin, the chosen warehouse still selected and the chosen type still selected.
Actual: zone/aisle/bin are blank, the 仓库 select snaps back to the first warehouse, and the 类型 select snaps back to storage.

Root cause confirmed at app/Modules/Warehouse/views/locations/index.blade.php:10-15 and :22-26 — no value="{{ old(...) }}" / @selected(old(...)) anywhere in the file, even though the ValidationException redirect from WarehouseController::store (app/Modules/Warehouse/Http/Controllers/WarehouseController.php:24-31) and LocationController::store (app/Modules/Warehouse/Http/Controllers/LocationController.php:26-32) does flash the old input.
- **原因**:Missing `value="{{ old('...') }}"` / `@selected(old(...))` on the two forms of this page.
- **修法**:Add `value="{{ old('code') }}"` … `value="{{ old('postcode') }}"` to the six warehouse inputs and `value="{{ old('zone') }}"`, `old('aisle')`, `old('bin')`, `@selected(old('warehouse_id') == $w->id)`, `@selected(old('type', 'storage') === $t)` to the location form.
- **工时**:约 0.5 小时 · 来源:static:Warehouse

### M6 · [Orders] 确认订单 button is rendered for every staff role but POST /orders/{order}/confirm only accepts admin / customer_service / dispatcher

- **页面**:GET /orders/{order} (app/Modules/Orders/views/show.blade.php:36-43) → POST /orders/{order}/confirm
- **受影响角色**:warehouse_supervisor, warehouse_operator, transport_operator, finance
- **复现**:Prereq: an order whose 操作状态 is 已接收 (received) — e.g. any freshly created order; note its id.
1. Log in as a user whose only role is warehouse_supervisor (repeat identically for warehouse_operator, transport_operator, finance).
2. Click 订单 in the nav (GET /orders) — the link is visible to every staff role.
3. Open the received order (GET /orders/{id}).
4. Observe the 确认 button rendered under the three status cards (show.blade.php:36-43), not disabled, with no role hint.
5. Click 确认 (POST /orders/{id}/confirm).
Actual: full-page 403 error screen naming 管理员 / 客服 / 调度 as the permitted roles; the user is thrown off the order page and must navigate back. Order stays in received.
Expected: the button should not render for these roles (or render disabled with a "需要客服/调度角色" hint), matching every other action block on the same page.
Control: log in as customer_service and repeat steps 3-5 — the order moves to 已确认 with a timeline event, proving the server rule is correct and only the Blade guard is missing.
- **原因**:Every other action block on this page carries an explicit hasAnyRole() guard (tailgate show.blade:137, holds :167, lines :186, return decision :304), but the confirm block was left ungated while the controller enforces order-entry roles.
- **修法**:Wrap the confirm form in `@if (auth()->user()->hasAnyRole(['admin','customer_service','dispatcher']))` (or show a disabled button with a 'need 客服/调度 role' hint), matching OrderController::authorizeOrderEntry().
- **工时**:约 0.5 小时 · 来源:static:Orders

### M7 · [Orders] 毛利 (margin) link is shown on every order but 404s for any order that has no transport shipment yet

- **页面**:GET /orders/{order} (app/Modules/Orders/views/show.blade.php:29-32) → GET /transport/orders/{orderId}/margin
- **受影响角色**:admin, customer_service, dispatcher, transport_operator, finance
- **复现**:Case A (pre-confirm / timing window):
1. Log in as customer_service (or admin/dispatcher/transport_operator/finance).
2. Create a normal outbound order (or open any existing order whose 操作状态 is 已接收 / `received`) and go to GET /orders/{order}.
3. In the 账务状态 card, the 毛利 link is rendered. Click it → GET /transport/orders/{order_id}/margin returns a bare 404 page (OrderMarginController.php:19 aborts because ShipmentMarginService::order() found no shipments).
4. Click 确认 (orders.confirm) and immediately click 毛利 again → still 404 until the `outbox:dispatch` scheduler (routes/console.php:7, every minute) runs OrderConfirmedConsumer and creates the shipment. After the cron tick, reload → page renders normally.

Case B (permanent, stronger):
1. As customer_service create/open a 退货 order (`order_type = 'return'`) and confirm it.
2. Wait for / run the outbox dispatch. OrderConfirmedConsumer returns early for `order_type === 'return'`, so no shipment is ever created for that order.
3. Open GET /orders/{return_order} and click 毛利 → 404 permanently, in every state of the order's lifecycle.

Expected: either the link is not rendered until the order has a shipment, or /transport/orders/{id}/margin renders an empty-state page ("尚无运输单，暂无毛利") instead of aborting 404.
- **原因**:The link's visibility condition covers roles only; the target 404s until Transport has booked at least one shipment for the order.
- **修法**:Render the link only when the order has a shipment (e.g. `$order->fulfilments->whereNotNull('shipment_id')->isNotEmpty()`), or have OrderMarginController render an empty-state page instead of 404.
- **工时**:约 0.5 小时 · 来源:static:Orders

### M8 · [Orders] Import and API-token forms lose their input after a validation error (no old() on selects/inputs)

- **页面**:GET /orders/imports/create (imports/create.blade.php:19), GET /orders/drafts/create (drafts/create.blade.php:18), GET /orders/api-tokens (api-tokens/index.blade.php:16-20)
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:As admin (or customer_service/dispatcher) go to GET /orders/imports/create. Pick a client + job + requested date, and change 服务等级 from 标准 to 特快 (express). For the file field pick any .csv/.xlsx larger than 10 MB (or rename a .pdf to .txt and select it via "All files") so server validation fails. Submit → you are redirected back to the form; client, job and date are repopulated via old(), but 服务等级 has silently reset to 标准. Re-pick a valid manifest file and submit again → the import is previewed/created with service_level=standard instead of the express the user chose. Same trace on GET /orders/drafts/create (drafts/create.blade.php:18) with a >10 MB or non-pdf/eml/txt document. On GET /orders/api-tokens (admin only) the equivalent is: type a token 名称 longer than 100 characters, submit → "name" validation fails and both the client select and the name input come back empty (note: the reviewer's "leave 名称 empty" variant is not reachable — the input has the HTML required attribute so the browser blocks submission).
- **原因**:old() repopulation was applied to client_id/job_id in some views but not to the remaining controls of these three forms.
- **修法**:Add `value="{{ old('name') }}"` and `@selected(old('client_id') == $client->id)` / `@selected(old('service_level', 'standard') === $level)` to the listed controls.
- **工时**:约 0.5 小时 · 来源:static:Orders

### M9 · [Portal] Expired final carrier quote still renders the 确认此方案 button; the server always refuses and the portal offers no way to get a fresh quote

- **页面**:POST /portal/orders/{order}/quotes/{quote}/confirm — app/Modules/Portal/views/orders/show.blade.php:112-134
- **受影响角色**:client
- **复现**:1. As transport staff, open an outbound shipment for a client order and issue final carrier quotes so the shipment sits at status 'quoted' and each transport_quotes row has quote_stage='final', status='quoted', expires_at = quoted_at + 24h.
2. Let the quote age past expires_at (wait >24h, or use an order that was quoted the previous day; do not mutate the live DB).
3. Sign in to the portal as a client user of that client and open /portal/orders/{id}.
4. In the 运输方案 section the shipment article still shows the 已报价 badge and the quote table lists each quote with 有效期至 showing yesterday's timestamp, with no 已过期 marker and no disabling — the 确认此方案 button is fully rendered and enabled (app/Modules/Portal/views/orders/show.blade.php:127-131).
5. Click 确认此方案. The POST reaches PortalQuoteController::confirm, QuoteSelectionService::select throws at app/Modules/Transport/Services/QuoteSelectionService.php:47, and the page returns with the flash error 该方案已过期，请重新报价。 Nothing changes and every retry fails; the portal exposes no re-quote action.
6. Compare the staff view of the same shipment (Transport → shipment detail): the action cell shows 无可用操作 instead of a button because app/Modules/Transport/views/shipments/show.blade.php:248 also requires $quote->expires_at->isFuture(). Expected in the portal: the same guard, plus an 已过期 badge and a 请联系客服重新报价 hint in place of the dead button.
- **原因**:can_confirm only mirrors the shipment status; the per-quote expiry that QuoteSelectionService enforces is not reflected in the portal view, unlike the staff shipment view which checks expires_at->isFuture().
- **修法**:In PortalTransportQuotes::forOrder compute a per-quote `can_confirm` (`$shipment->status === 'quoted' && $q->status === 'quoted' && Carbon::parse($q->expires_at)->isFuture()`), use it at show.blade.php:127, and render an 已过期 badge plus a 请联系客服重新报价 hint for stale rows so the client is not left clicking a dead button.
- **工时**:约 1 小时 · 来源:static:Portal

### M10 · [Portal] A goods line where the client only filled 箱数 is silently pruned; the error names `lines`, a field that is not on the form, and the typed 箱数 is thrown away

- **页面**:POST /portal/orders/preview and POST /portal/orders — app/Modules/Portal/views/orders/create.blade.php:96 → app/Modules/Orders/views/partials/goods-lines.blade.php
- **受影响角色**:client
- **复现**:1. Sign in as a client user (users.client_id set) and open /portal/orders/create; leave 订单类型 = 从库存出货 (from_stock).
2. Fill only what the browser insists on: 收货人 = 张三, 地址 = 12 Smith St, 郊区 = Clayton, 州 = VIC, 邮编 = 3168, 地址类型/服务等级 defaults, 要求日期 = today or later.
3. In 货物: leave 中文品名 and 英文品名 blank, 包装类型 = carton, 箱数 = 5, and leave 重量/长/宽/高 empty (key condition — filling any of them masks the bug).
4. Click 获取估价 (POST /portal/orders/preview); same behaviour via 提交订单 (POST /portal/orders).
Expected: an actionable goods-row error (中文品名/英文品名 至少填一个) with 箱数 = 5 preserved.
Actual: the page returns with "The lines field is required unless order type is in pickup_deliver." — an internal key with no matching label on the page — and the goods row is re-rendered with 箱数 = 1, so the typed 5 is lost while every other typed field comes back intact.
Contrast check: repeat with 重量 = 10 filled and everything else identical → the row survives prune, you get the proper 品名 error and 箱数 = 5 is kept, confirming the cause is carton_qty missing from OrderFormRows::LINE_CONTENT (app/Modules/Orders/Http/OrderFormRows.php:13).
- **原因**:Pruning treats carton_qty as a defaulted, meaningless value, but in the portal (extended = false) carton_qty is one of only six real inputs and is the one the form marks required. A row carrying only the client's 箱数 therefore vanishes, validation falls through to the array-level required_unless rule, and the message names an internal key. Because prune already replaced the input, the flashed old() no longer contains the typed quantity.
- **修法**:Add 'carton_qty' to OrderFormRows::LINE_CONTENT (or better, keep a row when it differs from the rendered default rather than field-by-field), so the row survives and the client gets the actionable "品名必填" error instead. Also add the `required` attribute (or a visible 必填 marker) to 中文品名 in goods-line-row.blade.php, and flash the raw input before pruning so nothing is lost on a bounce.
- **工时**:约 2 小时 · 来源:static:Portal

### M11 · [Portal] Rows added with 添加货物行 carry no required attribute, so a missing 箱数 on row 2+ only surfaces as the English message `lines.1.carton_qty`

- **页面**:POST /portal/orders/preview — app/Modules/Orders/views/partials/goods-line-row.blade.php via app/Modules/Portal/views/orders/create.blade.php:96
- **受影响角色**:client
- **复现**:1. Sign in as a client user and open /portal/orders/create (订单类型 left on 从仓库出货 / from_stock).
2. Fill the delivery block completely (收货联系人, 地址, 区/市, 州, 邮编, 地址类型, 要求日期, 服务等级) so nothing else blocks submission. Leave row 1 of 货物明细 exactly as rendered — 包装类型 = carton and 箱数 = 1 are prefilled.
3. Click 添加货物行. Inspect the new <tr data-index="1">: its 箱数 input has neither class="goods-required" nor the required attribute, while row 0's does.
4. In the new row type 中文品名 = 配件 and leave 箱数 empty. The browser raises no complaint on submit (row 1 is not required client side), and the row survives server-side pruning because description_cn counts as content in OrderFormRows::LINE_CONTENT.
5. Click 获取估价.
Observed: the page returns with the validation panel showing a single raw English bullet — "The lines.1.carton qty field is required." — inside an otherwise all-Chinese form. The tester must count table rows from zero to work out that 第2行的箱数 is the offending cell. (Only the required message appears; integer/min are skipped for the empty value.)
Expected: 箱数 (and 包装类型) in every added row carries the same required marker as row 1 so the browser catches it inline, and any server-side message names the field in Chinese with a 1-based row number.
- **原因**:The client-side required marker is asymmetric (first row only) while the server rule applies to all rows, and there are no Chinese attribute names to make the resulting message readable.
- **修法**:Render carton_qty / package_type with the `goods-required` class and required attribute on every row (toggleType() already flips them all off for pickup_deliver), and add lang/zh/validation.php `attributes` entries for lines.*.carton_qty etc.
- **工时**:约 1 小时 · 来源:static:Portal

### M12 · [Portal] One rejected 申请退货 closes the panel and wipes the reason and quantities the client typed

- **页面**:POST /portal/orders/{order}/returns — app/Modules/Portal/views/orders/show.blade.php:183-195
- **受影响角色**:client
- **复现**:Log in as a client user. Open /portal/orders/{id} for one of that client's own orders that is in a shipped state and is not itself a return (so the 申请退货 disclosure renders). Expand 申请退货 — every line's 退回箱数 is prefilled with the shipped/carton qty. Set EVERY line's 退回箱数 to 0 (allowed: min="0"), type a long free-text 原因 (e.g. a 200-character damage description), and submit. Server side: quantities.* is nullable|integer|min:0 so validation passes; the controller filters qty>0 to an empty array; ReturnRequestService::request throws OrderRuleViolation with orders.returns.messages.no_lines; the controller catches it and calls back()->withErrors(['return' => ...]) WITHOUT ->withInput(). Result: the order page reloads showing 请至少选择一行货物。 at the top, but the <details> panel is collapsed again, and on reopening the 原因 box is empty and all quantities are back to their defaults — the whole entry must be retyped. Note: the "quantity above shipped qty" variant in the original report is NOT reachable through a normal browser because the number input carries max="{{ $line->qty_shipped ?: $line->carton_qty }}"; use the all-zeros path (or a stale tab whose order has left the shipped state, which yields the not_shipped violation on the same lossy path). Fix must include ->withInput() on the catch branch in app/Modules/Portal/Http/Controllers/PortalReturnController.php:29-31 in addition to old('reason') / old('quantities.'.$line->id, ...) and @if ($errors->has('return')) open @endif on the <details> at app/Modules/Portal/views/orders/show.blade.php:183; old() alone will not repopulate anything.
- **原因**:The error path redirects back to a page that neither reopens the disclosure widget nor repopulates from old().
- **修法**:Add `@if ($errors->has('return')) open @endif` to the <details>, and wrap the reason and quantity values in old() (`old('reason')`, `old('quantities.'.$line->id, $line->qty_shipped ?: $line->carton_qty)`).
- **工时**:约 0.5 小时 · 来源:static:Portal

### M13 · [Portal] Every Portal validation failure is printed in English with raw dotted field names — there is no lang/zh/validation.php and no zh attribute names anywhere

- **页面**:all POST routes in app/Modules/Portal/routes.php — /portal/orders, /portal/orders/preview, /portal/orders/{order}/returns
- **受影响角色**:client
- **复现**:Sign in as a client user and open /portal/orders/create (中文 UI).

A) Opaque indexed names + duplicated list:
1. Fill the delivery block normally (name/address/suburb/state/postcode/要求日期/服务等级) so the browser's own required checks pass.
2. In 货品明细, fill row 1 completely (中文品名 + 箱数 = 1).
3. Click 添加货品行. In row 2 leave 中文品名, 英文品名 and 箱数 empty and type only a weight, e.g. 12 in the kg column (this is what stops OrderFormRows::prune from discarding the row).
4. Click 获取估价 (POST /portal/orders/preview).
Observed: the same English error list appears TWICE — once from layouts/partials/flash.blade.php at the top of the page, once under the Chinese heading 请修正以下内容： at create.blade.php:10 — reading "The lines.1.description cn field is required when lines.1.description en is not present." and "The lines.1.carton qty field is required." No label on the page says "lines.1.description cn"; the headers are 中文品名 / 箱数.

B) Pickup variant: set 订单类型 = 上门取件+派送, leave the 取件地址 fields blank, click 获取估价 -> "The pickup address line field is required when order type is pickup deliver."

C) Date: choose yesterday in 要求日期 (the input has no min) -> "The requested date field must be a date after or equal to today."

D) Return form: open a shipped order at /portal/orders/{id}, click 申请退货, clear 退货原因, submit -> "The reason field is required." (Do NOT expect "The quantities field is required." — those inputs are always prefilled.)

Expected: Chinese messages naming the Chinese labels (中文品名 / 箱数 / 取件地址 / 要求日期 / 退货原因), rendered once.
- **原因**:No Chinese validation translation file and no `attributes` map, while the array-based forms produce indexed machine names that have no visible counterpart on the page.
- **修法**:Add lang/zh/validation.php with the standard Chinese messages plus an `attributes` block covering deliver_to_*, requested_date, service_level, lines.*.description_cn/description_en/package_type/carton_qty, declared_packages.*.package_type/qty and quantities; and drop the duplicate error list at create.blade.php:10-12 now that layouts/partials/flash.blade.php renders $errors.
- **工时**:约 3 小时 · 来源:static:Portal

### M14 · [Transport] Transport nav shows 司机/班次/承运商对账 links to every staff role, but the controllers 403 them (driver page 403s even for admin)

- **页面**:resources/views/layouts/nav/transport.blade.php (rendered on every page by layouts/nav.blade.php) → GET /driver, /transport/runs, /transport/carrier-invoices
- **受影响角色**:admin, dispatcher, customer_service, finance, warehouse_supervisor, warehouse_operator
- **复现**:Setup: any browser session, live nav (the include is rendered on every page by resources/views/layouts/nav.blade.php).

1. Log in as a user with role `finance` only. Open the 运输 dropdown in the top nav. Observed: it lists 运输 / 班次 / 承运商对账 / 司机 / 异常. Click 班次 (GET /transport/runs). Expected: the link is not shown to finance. Actual: the no-permission 403 page ("需要角色: 管理员 / 客服 / 调度 / 运输操作员").
2. Same session, click 司机 (GET /driver). Actual: 403 (DriverController allows only `transport_operator`).
3. Log out, log in as `dispatcher` (or `customer_service`). Open 运输 → click 承运商对账 (GET /transport/carrier-invoices). Actual: 403 (allowed: admin / transport_operator / finance).
4. Log in as `warehouse_operator`. Open 运输 → 班次, 承运商对账, 司机 all 403; only 运输 and 异常 work.
5. Contrast: log in as the same `finance` user and open the 结算 dropdown — resources/views/layouts/nav/billing.blade.php:2 gates its links, and warehouse/orders/reports/masterdata do the same, so no other module produces a dead nav link.

Fix direction: wrap the three gated links in resources/views/layouts/nav/transport.blade.php to match the servers — `@role('admin|customer_service|dispatcher|transport_operator')` around 班次, `@role('admin|transport_operator|finance')` around 承运商对账, `@role('transport_operator')` around 司机. Leave 运输 (transport.index) ungated: IndexController has no role check, so every staff role may open it. 异常 follows platform.exceptions.index's own rules.
- **原因**:The Transport nav include is the only one in resources/views/layouts/nav/ with no @role wrapper, while all Transport authorisation lives in controller-level abort_unless / RequiredRoles calls (routes/web.php:15 applies only `auth` + `client.scope`). The Blade guard and the server check were never aligned.
- **修法**:Wrap each <li> in the matching @role: `@role('admin|customer_service|dispatcher|transport_operator|finance')` around 运输, `@role('admin|customer_service|dispatcher|transport_operator')` around 班次, `@role('admin|transport_operator|finance')` around 承运商对账, `@role('transport_operator')` around 司机. Separately decide whether DriverController should use hasAnyRole(['admin','transport_operator']) so an admin can inspect the driver page.
- **工时**:约 1 小时 · 来源:static:Transport

### M15 · [Transport] 人工报价 with a markup above 999.99% writes to decimal(5,2) and returns a 500, losing the whole form

- **页面**:POST /transport/{shipment}/quotes/manual — views/shipments/show.blade.php:59-80, ManualQuoteController
- **受影响角色**:admin, customer_service, dispatcher, transport_operator
- **复现**:Precondition: a shipment whose status is 报价中 (quoting) or 已报价 (quoted) and at least one active carrier_service with source='manual' (the DemoFlowSeeder/MasterData manual services qualify). 1) Log in as dispatcher (or admin / customer_service / transport_operator). 2) Open 运输 > the shipment detail page, /transport/{shipment}. 3) The 人工录入运输报价 panel is open by default; leave 承运商与服务 at any manual option. 4) 报价阶段 = 最终 (final). 5) 内部成本（分） = 5000. 6) 客户价（分） = 60000 (any value more than 10.9999x the cost triggers it; this mimics an operator typing one extra zero). 7) 预计时效（天） = 3. 8) Click 保存人工报价. Expected: either the quote saves or an inline Chinese validation error. Actual: HTTP 500 error page. Laravel log shows Illuminate\\Database\\QueryException SQLSTATE[22003] "Out of range value for column 'markup_percent'" from the INSERT in ManualQuoteService::record (app/Modules/Transport/Services/ManualQuoteService.php:78 computes ((60000/5000)-1)*100 = 1100.00 into decimal(5,2), max 999.99). Pressing Back shows the form empty — carrier service, stage, cost, price and ETA all lost, because a 500 does not flash old input. No quote row is created (the surrounding DB::transaction rolls back) and the shipment status is unchanged. Boundary check: 内部成本 5000 with 客户价 54999 (markup 999.98%) saves fine; 55000 (1000.00%) is the first failing value.
- **原因**:Unbounded computed markup written into a decimal(5,2) column; no clamp in the service and no proportional limit in the validation rules.
- **修法**:Clamp in ManualQuoteService: `min(999.99, max(-999.99, round(...)))`, or widen the column to decimal(8,2) in a new migration. Additionally add `'customer_price_cents' => ['required','integer','min:1','lte:...']`-style guidance or a DomainException with a Chinese message so the user sees an actionable error instead of a 500.
- **工时**:约 1.5 小时 · 来源:static:Transport

### M16 · [Transport] Run stop re-ordering rejects the natural single-number edit with an English 'duplicate value' error naming a stop id, and clears the typed numbers

- **页面**:PATCH /transport/runs/{deliveryRun}/stops/order — views/runs/show.blade.php:51-85, RunStopController::reorder
- **受影响角色**:admin, customer_service, dispatcher, transport_operator
- **复现**:Login as dispatcher (or admin / customer_service / transport_operator). Open Transport → 运输行程 → a run whose status is 计划中 (planned) that has at least 3 stops with seq 1,2,3 — /transport/runs/{id}. In the 站点 table each row shows an editable 顺序 number box. Change only the third row's box from 3 to 1, leave the other two at 1 and 2, click 保存顺序 (PATCH /transport/runs/{id}/stops/order). Expected: the stop moves to the front, or a Chinese message explaining the numbering rule. Actual: the page reloads with a red flash containing the untranslated Laravel default «The positions.37 field has a duplicate value.» (and a second copy for the other duplicated row), where 37 is the internal run_stops.id — meaningless in the UI, no column shows it. All three 顺序 boxes are repainted with the stored 1/2/3, so the typed value is gone. To succeed the user must renumber every box to a distinct value in a single submit (e.g. 1,2,3 → 2,3,1). Note the run must be 计划中: for dispatched/completed runs the seq is plain text and the button is hidden, so the bug is unreachable there.
- **原因**:The form is a free-text numbering grid validated with `distinct`, which makes the most common gesture (change one number) invalid; the failure path neither repopulates input nor produces a translated, human-readable message.
- **修法**:Either drop `distinct` and normalise by rank in the controller (`asort` already orders by value; ties can be broken by existing seq), or keep it but repopulate with `old('positions.'.$stop->id, $stop->seq)`, add ->withInput() at RunStopController.php:50, and add a zh message plus a zh attribute name for `positions.*` (e.g. via a lang/zh/validation.php with 'attributes' mapping) so the error reads «顺序不能重复».
- **工时**:约 2 小时 · 来源:static:Transport

### M17 · [Transport] Driver POD: 提交送达 does nothing and shows no message when the signature canvas is untouched (required on a hidden input is inert)

- **页面**:POST /driver/stops/{runStop}/deliver — app/Modules/Transport/views/driver.blade.php:68-88 + inline script :112-163
- **受影响角色**:transport_operator
- **复现**:Log in as a user with the transport_operator role who is the driver_id on a DeliveryRun with run_date = today and status planned or dispatched, holding at least one RunStop whose shipment has no POD with delivered_at set. Open GET /driver, expand 记录送达 on that stop. Fill 收件人姓名 (e.g. "Wang") and attach one JPEG/PNG/WEBP under 5 MB to 照片, but do NOT draw on the signature canvas. Click 提交送达. Expected: a visible message "请先在签名框中签名。". Actual: nothing at all — no validation bubble, no navigation, no server round-trip, no error text; the button appears dead. Second, equivalent path: draw a signature, then click 清除签名 (which resets signed=false and blanks the canvas) and click 提交送达 — same total silence. Verify in DevTools that no request is issued (the submit handler at app/Modules/Transport/views/driver.blade.php:152-158 calls preventDefault) and that document.querySelector('[data-signature-data]').willValidate === false, proving required/setCustomValidity/reportValidity on the type=hidden input are inert. Contrast: leaving 收件人姓名 or 照片 empty DOES show a native bubble, because those are visible, validatable controls — which makes the silent failure specific to the signature.
- **原因**:Validation feedback is attached to an element the browser refuses to validate, so the JS guard silently swallows the submit.
- **修法**:Render the message in the DOM instead of relying on the hidden input: keep the preventDefault but insert/reveal a <p role="alert"> next to the canvas (or move the constraint onto a visible, readonly text input). One-line addition in the submit handler plus a hidden <p> in the form.
- **工时**:约 0.5 小时 · 来源:static:Transport

### M18 · [Transport] Confirming a transport quote shows no confirmation at all — the controller flashes 'success' but the layout only renders 'status'

- **页面**:POST /transport/{shipment}/quotes/{quote}/select — views/shipments/show.blade.php:249, QuoteSelectionController
- **受影响角色**:admin, customer_service, dispatcher, transport_operator
- **复现**:Log in as dispatcher. Open an outbound shipment in status 已报价 (quoted) with at least two non-expired PRELIMINARY quotes (Transport > shipment detail, quotes table). Press 选择 on a preliminary quote row. The POST succeeds and persists (shipment.selected_quote_id / carrier_id / service_level update), but the page redirects back with no green flash bar — the controller flashes under key 'success' while resources/views/layouts/partials/flash.blade.php renders only session('status'). The only on-screen evidence is the small 当前选择 badge shifting to another row; every 选择/确认 button stays visible because the shipment is still 已报价, so the tester cannot tell the action registered. (A FINAL quote loses the same flash, but the shipment moves to 已确认报价 and all buttons disappear, so the missing feedback is far less visible — use a preliminary quote to demonstrate.) Fix: change 'success' to 'status' at app/Modules/Transport/Http/Controllers/QuoteSelectionController.php:31.
- **原因**:Wrong flash key — a one-word divergence from the shared partial's contract.
- **修法**:Change 'success' to 'status' in QuoteSelectionController.php:31.
- **工时**:约 0.25 小时 · 来源:static:Transport

### M19 · [Transport] Every Transport validation failure prints English with raw field names, and most POST handlers drop the submitted input

- **页面**:all Transport POST/PATCH endpoints (booking, manual quote, run create, run stop add, run reorder, driver deliver/fail, carrier invoice import)
- **受影响角色**:admin, customer_service, dispatcher, transport_operator, finance
- **复现**:Use a reachable path — the reviewer's two steps are blocked by HTML `required`/`step="1"` and cannot be reproduced.

A) Duplicate stop sequence (dispatcher, 2 min):
1. Log in as dispatcher. 运输 → 班次 → open a run whose status is planned and that has at least two stops (/transport/runs/{id}).
2. In the 顺序 column of the 站点 table, type the same number (e.g. 1) into two rows.
3. Submit the reorder form. The red box at the top of the page reads: «The positions.37 field has a duplicate value.» — English, with the raw stop-id key `positions.37`, which maps to no visible control. (Rule: RunStopController.php:41 `positions.* => distinct`; rendered at app/Modules/Transport/views/runs/show.blade.php:69.)

B) Driver POD without a signature (transport_operator on a phone, 1 min) — cleanest case, because the field is a hidden input and `required` on type=hidden is ignored by browser constraint validation, so the form really does reach the server:
1. Open /transport/driver, expand 记录送达 on a stop.
2. Fill 收件人 and attach a photo, do NOT draw on the signature canvas, submit.
3. Red box: «The signature data field is required.» (DriverController.php:39; hidden input at app/Modules/Transport/views/driver.blade.php:79.)

C) Carrier invoice CSV (finance): 运输 → 对账, attach a .xlsx renamed to something the mimes:csv rule rejects, or a file over the size cap → English message naming `statement` / `total_cents` / `period_from` (carrier-invoices/index.blade.php:23-25).

Input loss — corrected scope. Validation failures DO repopulate the forms (Laravel flashes input automatically on ValidationException), so there is nothing to fix there. Input is only lost on a business-rule rejection, and only in three places, none of them a blocker:
- 人工报价: on a shipment already past 报价 status, submit the 人工报价 form → transport.manual_quote.invalid_status → the three number fields you just typed are cleared (ManualQuoteController.php:38, no ->withInput()).
- 订舱: book a shipment whose client is on financial hold → transport.booking.financial_hold → 订舱参考/运单号 cleared (ShipmentBookingController.php:32).
- 班次加站: add a shipment already assigned to another run → transport.runs.already_assigned → the ETA you picked is cleared (RunStopController.php:30).
Ignore the claim for QuoteSelectionController.php:28 and RedeliveryController.php:24 (button-only POSTs, no input to preserve) and for RunStopController.php:50 (positions are re-rendered from the DB, not from old()).

Fix that actually addresses it: add lang/zh/validation.php (zh_CN template) with an `attributes` map — and note this is platform-wide, not Transport-only: Orders, Portal, Warehouse and Billing hit the same English fallback, so the attributes map should cover those field names too. Optionally add ->withInput() to the three controllers above and an old()-driven @selected to the shipment_id select at runs/show.blade.php:29-36.
- **原因**:No zh validation translation file and no 'attributes' map; ->withInput() applied inconsistently across the module's controllers.
- **修法**:Add lang/zh/validation.php (Laravel's zh_CN template) plus an 'attributes' section covering the Transport field names (cost_cents, customer_price_cents, eta_days, driver_id, shipment_id, positions.*, photos.*, signature_data, period_from/to, total_cents, statement), and add ->withInput() to the five controllers listed. Add old() to runs/show.blade.php:32 and :40.
- **工时**:约 3 小时 · 来源:static:Transport

### M20 · [Billing] Credit-note 开具 button is always refused on first click — approval state is neither shown nor enforced in the UI

- **页面**:GET /billing/invoices/{invoice} → app/Modules/Billing/views/invoices/show.blade.php (POST /billing/credit-notes/{note}/issue)
- **受影响角色**:admin, finance
- **复现**:Preconditions: two staff accounts with role finance (or one finance + one admin), e.g. finance A and finance B; an invoice in status issued/part_paid/paid.

1. Log in as finance A, open GET /billing/invoices/{invoice} (an issued invoice).
2. Expand 新建 credit note under the 贷项通知单 (Credit notes) card, type a 原因, put an amount > 0 on at least one line, submit.
   → Flash (zh): "Credit note #N 草稿已建立并送审批(需第二人批准)。" The note row appears with badge 草稿(待审批) and an enabled 开出 credit note button (app/Modules/Billing/views/invoices/show.blade.php:61 renders it for any status !== 'issued' with no @disabled and no per-note approval state — InvoiceController::show() at app/Modules/Billing/Http/Controllers/InvoiceController.php:73-79 passes no $approved/$pendingApproval, unlike RateCardController::show()).
3. Still as finance A, click 开出 credit note.
   → POST /billing/credit-notes/{note}/issue returns back()->withErrors, and the flash partial (resources/views/layouts/partials/flash.blade.php:4) shows the raw, untranslated English string "This credit note has not been approved by a second person yet." (CreditNoteService.php:56-57). No link to the approval queue is offered from the invoice page, and finance A can never clear it themselves (ApprovalService::decide rejects requested_by === decider).
4. Log in as finance B (or admin) → nav 审批 → /admin/approvals → approve the pending credit_note request.
5. Back as finance A on the invoice page: the badge still reads 草稿(待审批) (there is no "已批准" state for a drafted note; CreditNote status never becomes 'approved'). Click 开出 credit note → it now succeeds, CN number issued.

So the flow completes normally once the mandated second person acts; the defect is purely presentational: an always-enabled button that produces an untranslated English refusal on the first click, plus a badge that never reflects the approved-but-not-yet-issued state.
- **原因**:InvoiceController::show() does not load the credit-note approval state, so the Blade cannot gate the button the way the rate-card page does; the only feedback is a raw English exception with no pointer to the approval queue.
- **修法**:In InvoiceController::show() compute per-note approval state (ApprovalService::isApproved + pending lookup, same two lines as RateCardController::show), then in invoices/show.blade.php render @disabled(! $approved) plus a 待审批 badge linking to route('platform.approvals.index'); add zh strings for both states.
- **工时**:约 1.5 小时 · 来源:static:Billing

### M21 · [Billing] Percent-mode charge codes (e.g. TR-FUEL 10%) always quote as $0.00 with no POA / missing-rate flag

- **页面**:GET /billing/quotes/create → app/Modules/Billing/views/quotes/create.blade.php (POST /billing/quotes)
- **受影响角色**:admin, finance, customer_service
- **复现**:Precondition: demo data seeded (DemoFlowSeeder); client EDWARD has an active client rate card containing TR-FUEL with pricing_mode=percent, markup_percent=10 (DemoFlowSeeder.php:156). Confirm on /billing/rate-cards/{edward card} that TR-FUEL shows the percent mode with 10%.

1. Log in as customer_service@erp.local (admin or finance work identically — same route group).
2. Open GET /billing/quotes/create.
3. Client = Edward Logistics; Stage = preliminary; keep the default valid-until date.
4. Line 1: charge code TR-DELIVERY-BASE, qty 1 (gives the quote a real freight line to surcharge; it is a fixed 9500 item).
5. Line 2: charge code TR-FUEL, qty 1. Leave 重量 and 成本 blank, or fill them with any numbers — no field on this form maps to the percent mode's base_cents.
6. Submit (POST /billing/quotes) and land on /billing/quotes/{id}.

Observed: the TR-FUEL line shows 金额 $0.00 with NO 待报价 badge (assumptions show is_poa=false, missing_rate=false, pricing_mode=percent, empty context), and the quote total plus GST silently omit the 10% fuel surcharge (~$9.50 against the $95.00 delivery line).

Expected per RateService's own contract ("missing rate (never $0)"): either the quote line offers a base-amount input mapped to base_cents, or RateService returns missing_rate/is_poa = true for pricing_mode=percent with no base, so the line renders 待报价 instead of a priced-looking $0.00.

Control: add TR-TAILGATE qty 1 to the same quote — it prices $45.00 correctly, proving the fault is the percent pricing mode, not the client/card lookup.
- **原因**:The quote form's context vocabulary (weight_kg, cost) does not cover the percent pricing mode's input (base_cents); RateService silently defaults it to 0 instead of reporting an unpriceable line.
- **修法**:Either add a 基数 (base amount) input to the quote line and map it to base_cents, or have RateService return missing_rate/is_poa = true when pricing_mode='percent' and base_cents is absent, so the line is flagged 待报价 instead of $0.
- **工时**:约 1.5 小时 · 来源:static:Billing

### M22 · [Billing] Rate-card and credit-note forms lose everything the user typed on a validation error (no old(), and <details> re-collapses)

- **页面**:GET /billing/rate-cards/{card} → app/Modules/Billing/views/rate_cards/show.blade.php (POST /billing/rate-cards/{card}/items); also rate_cards/index.blade.php and invoices/show.blade.php credit-note block
- **受影响角色**:admin, finance
- **复现**:Two independent confirmations; run either or both. Log in as finance (or admin).

A) Rate card add-item (server-only trigger, corrected)
1. GET /billing/rate-cards → open the 新建费率卡 <details>, create a card (it lands in status=draft), or open any existing draft card at GET /billing/rate-cards/{card}.
2. Expand the 新增费率项 <details> at the bottom of the page.
3. Fill in as many fields as you can bear: pick a 费用代码, set 单价 12.50, 最低收费 30, 板类, 重量区间 min 0 / max 500, 区域 METRO, 服务等级 STD, tick POA off.
4. In 阈值 type an unquoted-key object exactly as a user would: {tailgate_weight_kg: 25}
   (Do NOT use plain 25 — a bare scalar is valid JSON and passes; the reviewer's original repro is wrong on this point.)
5. Submit.
Observed: red banner "The threshold json field must be a valid JSON string." at the top of the page; the 新增费率项 <details> is CLOSED again. Reopen it: 单价, 最低收费, 重量区间, 区域, 服务等级, 阈值 are all empty, and 费用代码 / 计价方式 / 板类 have snapped back to their first option rather than what you chose. Everything must be re-entered, and the reset selects can be resubmitted wrong without you noticing.

B) Credit note (no malformed input needed at all)
1. Open an issued invoice: GET /billing/invoices/{invoice} where status is issued / part_paid / paid.
2. Expand the 新建贷项通知单 <details>.
3. Type a 原因, e.g. "客户投诉货损，部分退款", and leave every line 金额 blank (or type 0 in one) — an easy real mistake when you mean to fill one line and tab past it.
4. Submit.
Observed: red banner "A credit note needs a positive amount."; the <details> is closed; reopening shows 原因 blank and every line amount blank.

Root cause, confirmed in source: app/Modules/Billing/views/rate_cards/show.blade.php:45-61, app/Modules/Billing/views/rate_cards/index.blade.php:10-18 and app/Modules/Billing/views/invoices/show.blade.php:64-72 contain no old() and no @selected(old(...)), and each form sits inside a plain <details> with no open state bound to $errors. Laravel does flash the input (both $request->validate() and back()->withErrors() at RateCardController.php:64 / InvoiceController.php:127) — the Blade simply never reads it. Compare app/Modules/Billing/views/charges/manual.blade.php:11-17, which does it correctly.

Fix as proposed: add old('field') / @selected(old(...)) defaults to all three forms and render <details @if($errors->any()) open @endif>.
- **原因**:back()->withErrors() without repopulation: the Blade fields have no old() defaults and the collapsible <details> has no open state tied to $errors, so a single bad field discards the whole form.
- **修法**:Add old('field') defaults (and @selected(old(...)) on the selects) to the three forms, and render <details @if($errors->any()) open @endif> so the block reopens with the values and the error visible.
- **工时**:约 1 小时 · 来源:static:Billing

### M23 · [Billing] 按账期生成草稿 defaults (本月 + 服务费) do not match the client total shown above it, so the first click often errors

- **页面**:GET /billing/unbilled → app/Modules/Billing/views/invoices/unbilled.blade.php (POST /billing/invoices/period)
- **受影响角色**:admin, finance
- **复现**:1) Log in as finance (or admin). 2) Ensure a client has unbilled charges (charges.status in pending/approved, invoice_line_id NULL) whose charge_date all fall in the PREVIOUS month — or whose charge codes are all category='storage' (the weekly storage run) dated in the current month. 3) GET /billing/unbilled: the card header for that client shows a non-zero 未开票 amount (unfiltered sum of the whole pool). 4) In that card's 按账期生成草稿 form change nothing — 账期从 = 1st of current month, 到 = end of current month, 包含费用 = 服务费(不含仓储) are pre-filled — and click 按账期生成草稿 (POST /billing/invoices/period). 5) Expected: a draft matching the amount in the header, or at least a hint of which period/scope holds it. Actual: redirect back to /billing/unbilled with a red English flash 'No unbilled charges for this client in the period.' (untranslated in the zh UI). 6) Confirm it is only the defaults: click the 上月 preset (last-month case) or select 仓储费 / 全部费用 (storage case) and resubmit — the draft is created normally.
- **原因**:Static defaults instead of defaults derived from the pool that the same page just computed; the displayed total and the form's filter are computed from different criteria.
- **修法**:Derive the default from/to from min/max charge_date of that client's unbilled pool (and preselect scope='all' when the pool mixes storage and service), and show the per-scope split in the header so the figure and the button agree.
- **工时**:约 1 小时 · 来源:static:Billing

### M24 · [Billing] Quote form rows 3-5 stay hidden after a validation error, so an error naming lines.3.* points at an invisible field

- **页面**:GET /billing/quotes/create → app/Modules/Billing/views/quotes/create.blade.php (POST /billing/quotes)
- **受影响角色**:admin, finance, customer_service
- **复现**:Log in as customer_service (or finance/admin) and open GET /billing/quotes/create (新建报价).
1. Pick any client in 客户.
2. Leave the three visible line rows (indexes 0-2) completely empty.
3. Click 加一行 once — row index 3 appears.
4. In that newly revealed row choose any charge code from the 编码 dropdown, but leave 数量 blank (do NOT type a bad number; the input is type=number/min=0 so the browser blocks non-numeric or negative values before they ever post).
5. Submit 新建报价.

Expected: the row you filled in stays visible so you can see and fix it.
Actual: the POST reaches QuoteController::store, all line rows pass validation (`lines.*.charge_code` and `lines.*.qty` are nullable), the filter drops the row because qty is not > 0, and the controller returns `back()->withErrors(['lines' => __('billing.quotes.no_lines')])->withInput()`. On the redisplayed form the error banner is shown, but create.blade.php:18 re-applies `hidden` to rows 3-5 from the loop index alone, so the charge code you selected is invisible — the form looks entirely empty next to a "no lines" error. Clicking 加一行 again reveals row 3 with the selected code still in it, proving the value was retained and re-submitted all along.

Alternate trigger for a field-level error on a hidden row: fill row index 3 correctly (code + qty 1), then paste 1001+ characters into 备注 (the notes input has no maxlength attribute) and submit — validation fails on `notes` max:1000 and row 3 is hidden again with its data intact.
- **原因**:The hidden attribute is derived from the loop index rather than from whether the row has old() input or an error bag entry.
- **修法**:Change the condition to @if ($i > 2 && ! old("lines.$i.charge_code") && ! old("lines.$i.qty") && ! $errors->has("lines.$i.qty")) hidden @endif so any row the user touched (or that failed validation) stays visible after a round trip.
- **工时**:约 0.5 小时 · 来源:static:Billing

### M25 · [Platform] Job workbench shows the Billing charges panel + "打开" link to customer_service / dispatcher, but /billing is role:admin|finance → 403

- **页面**:GET /jobs/{job} — app/Modules/Platform/views/jobs/show.blade.php
- **受影响角色**:customer_service, dispatcher
- **复现**:Prereq: a job with at least one charge whose status != 'reversed' (e.g. any container/receiving job from DemoFlowSeeder), and a user holding role customer_service (or dispatcher).

1. Log in as the customer_service user.
2. Go to /jobs and click the job number of that job (route platform.jobs.show — only 'auth' + 'client.scope' middleware, so CS/dispatch are admitted; app/Modules/Platform/routes.php:31-36).
3. Scroll past 创建时间 to the bottom of the page. The 作业费用 (billing.job_panel.title) table renders — the @role('admin|finance|customer_service|dispatcher') guard at show.blade.php:87 passes because Spatie hasRole() splits the pipe string (vendor/spatie/laravel-permission/src/Traits/HasRoles.php:252).
4. In the table footer, rightmost cell, click 打开 (billing.job_panel.open) → GET /billing?job_no=<JOB_NO>.
5. Observed: HTTP 403 "User does not have the right roles." — billing.index sits inside Route::middleware('role:admin|finance') (app/Modules/Billing/routes.php:22-23); 'role' is aliased to Spatie RoleMiddleware in bootstrap/app.php:22 and there is no custom exception handler (no app/Exceptions/), so the raw 403 page is shown.
6. Repeat as dispatcher — identical.
7. Control: as admin or finance the same link loads the charge register filtered by job_no, confirming the link target is correct and only the audience is mismatched.

Corroborating evidence that admin|finance is the intended audience (i.e. the fix belongs on the Blade link, not the route): resources/views/layouts/nav/billing.blade.php:2 guards the same route with @role('admin|finance'), and only billing.quotes.* is widened to customer_service (routes.php:14).
- **原因**:The Blade @role guard on the panel (admin|finance|customer_service|dispatcher) is wider than the route middleware on the target it links to (role:admin|finance). The panel was widened for CS/dispatch visibility but the drill-through link was not guarded separately.
- **修法**:Wrap only the footer link in its own guard, e.g. `@role('admin|finance')<a href="{{ route('billing.index', ...) }}">…</a>@else<span class="text-muted">…</span>@endrole`, or add customer_service to the `role:admin|finance` group for `billing.index` if CS is meant to read charges. Cheapest correct fix is the Blade-side guard.
- **工时**:约 0.5 小时 · 来源:static:Platform

### M26 · [Platform] transport_operator lands on /jobs but every Job page links into /warehouse/**, which its role middleware refuses

- **页面**:GET /jobs/{job} — app/Modules/Platform/views/jobs/show.blade.php
- **受影响角色**:transport_operator
- **复现**:Seed/select a user whose only role is transport_operator (Enums::ROLES, app/Support/Enums.php:11) and log in at /login. You land on /jobs (bootstrap/app.php:34 → platform.index). Open any job that has at least one ASN, e.g. /jobs/{id}. In the 预报 panel, click the ASN number (href = /warehouse/asns/{id}) → HTTP 403 (Spatie RoleMiddleware, app/Modules/Warehouse/routes.php:22 role list omits transport_operator). Go back and in the 库存 panel click 打开库存 (href = /warehouse) → HTTP 403. Both links render unconditionally at app/Modules/Platform/views/jobs/show.blade.php:37 and :45; compare resources/views/layouts/nav/warehouse.blade.php:2, which correctly wraps the same destinations in @role('admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance'). Expected: link hidden / rendered as plain text for roles without warehouse read.
- **原因**:Platform's job page renders cross-module links unconditionally, while the Warehouse read group deliberately excludes transport_operator. Note line 58 of the same file does guard the Transport link with `Route::has(...)`, so the omission is inconsistent within the file itself.
- **修法**:Guard the two warehouse links with `@role('admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance')` (or a shared `@can('warehouse.read')` gate) and render plain text for other roles. Do the same for `orders.show`/`billing.index` drill-throughs if their role lists ever narrow.
- **工时**:约 1 小时 · 来源:static:Platform

### M27 · [Platform] Approval Centre offers 批准/驳回 to the requester; ApprovalService always throws "A request must be decided by a second person"

- **页面**:GET /admin/approvals + POST /admin/approvals/{approval}/approve — app/Modules/Platform/views/approvals/index.blade.php
- **受影响角色**:admin, finance
- **复现**:Seeded users admin@erp.local and finance@erp.local (both hold a role that passes role:admin|finance).

1. Log in as finance@erp.local.
2. Go to /billing/rate-cards, open (or create) a draft card, and click 提交审批 (POST /billing/rate-cards/{card}/request-activation). RateCardService::requestActivation passes $request->user() to ApprovalService::request, so the finance user is the requester.
3. Go to 平台 → 审批中心 (/admin/approvals, default filter 待审批). The row you just created shows 批准 and 驳回 buttons, because index.blade.php:29-33 gates them on @role('admin|finance') alone.
4. Type any note and click 批准 (POST /admin/approvals/{approval}/approve). The route's role:admin|finance middleware passes; the controller validates only note (nullable|string|max:500); ApprovalService::decide() then throws at line 66-68.
5. You are redirected back and the flash partial renders the raw English string: "A request must be decided by a second person (PLT-7)." 驳回 behaves identically. Same result for an admin who raises a credit note via POST /billing/invoices/{invoice}/credit-notes.

Correct behaviour is still reachable: log in as admin@erp.local and click 批准 on the same row — it succeeds. The requester's own 撤回 button also works (it is correctly guarded by $a->requested_by === auth()->id() at line 34).

Expected: on your own pending request the 批准/驳回 buttons should not render at all (only 撤回), and any service-level refusal should surface as a zh string, not raw English.
- **原因**:The Blade guard only checks the role, never `$a->requested_by === auth()->id()` — the very condition the service enforces. Two lines below (line 34) the same file does compare `requested_by` for the 撤回 button, so the check exists but was not applied to approve/reject. The raw English exception message is also surfaced verbatim to the tester.
- **修法**:Change line 30 to `@if (auth()->user()->hasAnyRole(['admin','finance']) && $a->requested_by !== auth()->id())` (or add `@if ($a->requested_by !== auth()->id())` inside the @role block), and translate the service message via a zh key (e.g. `platform.approvals.self_decide`) before returning it with withErrors().
- **工时**:约 0.5 小时 · 来源:static:Platform

### M28 · [Platform] Exception Centre 来源 link for warehouse-sourced exceptions 403s for transport_operator

- **页面**:GET /admin/exceptions — app/Modules/Platform/views/exceptions/index.blade.php + ExceptionController::sourceUrl
- **受影响角色**:transport_operator
- **复现**:Precondition: at least one open exception with source_type in (asn, asn_line, stock_unit, stocktake) — e.g. a receiving discrepancy, a quarantine, or a stocktake variance; plus one with source_type = outbox_event from a failed integration event.

1. Log in as the transport_operator (driver) user.
2. Top nav → 平台 → 异常中心 (GET /admin/exceptions). The page loads (route group allows transport_operator).
3. Locate a row whose 模块 column reads 仓库. Under the 消息 text there is a second line: 来源: asn #NN (or stock_unit / stocktake).
4. Click it → browser goes to /warehouse/asns/NN and renders a 403 Forbidden page. Back button returns to the list; the row's 认领 / 处理中 / 解决 controls still work normally.
5. Same-bug variant with wider blast radius: log in as any non-admin staff role (customer_service is easiest), open /admin/exceptions, find a row whose 来源 reads outbox_event #NN, click it → /admin/integration → 403, because that route is admin-only (app/Modules/Platform/routes.php:62).

Expected: when the current user's role cannot open the target page, ExceptionController::sourceUrl should return null so index.blade.php:29 renders the source as plain text instead of a link.
Actual: sourceUrl only calls Route::has(), which is true regardless of role, so the anchor is always rendered.

Not affected (verified): the 'order' and 'shipment' branches — Orders/routes.php and Transport/routes.php apply no role middleware to orders.show / transport.shipments.show.
- **原因**:sourceUrl() only checks Route::has() (does the route exist in this build) and never checks whether the current user's role may open it, while the Exception Centre itself is open to every staff role including transport_operator.
- **修法**:Add a role check alongside Route::has in ExceptionController::sourceUrl — e.g. a small map of route-name → allowed roles, or `auth()->user()->hasAnyRole([...])` per branch — returning null (plain text, no link) when the user cannot open the target.
- **工时**:约 1 小时 · 来源:static:Platform

### M29 · [Platform] Document Centre: ticking 客户可见 without picking a 客户 produces a document no client user can ever see, and the UI reports success

- **页面**:POST /admin/documents and POST /admin/documents/{document}/visibility — app/Modules/Platform/views/documents/index.blade.php
- **受影响角色**:admin, customer_service, dispatcher, warehouse_supervisor, warehouse_operator, transport_operator, finance
- **复现**:1. Log in as customer_service (or admin / dispatcher / warehouse_supervisor / warehouse_operator / transport_operator / finance — all pass the $staff middleware) and open /admin/documents.
2. Expand 上传单据. Pick any PDF; 类型 = POD; 关联类型 = 订单; 关联 ID = the id of an order belonging to client C; leave 客户 on "—"; leave 单号 ID empty; tick 客户可见; submit.
3. Expected: rejected with a message that 客户 is required when 客户可见 is ticked (or client derived from the related record). Actual: green flash 已上传; the new row shows 客户 = "—" with a green 客户可见 badge. In the DB the row is client_id = NULL, client_visible = 1.
4. Note the new document id N (first column). Log in as a portal user of client C and open /portal/documents/N directly. Expected: the file downloads. Actual: 404 — ClientGlobalScope adds `where documents.client_id = C`, which excludes the NULL row. (Do not test via the order page: /portal/orders/{id} only lists PODs attached through shipment pods, so no Document-Centre upload shows there regardless of client_id.)
5. Back as staff, press 设为隐藏 then 设为可见 on that row: both return 已保存 and flip the badge while 客户 stays "—". The same toggle reproduces the state on any existing row whose 客户 column is "—".
6. No screen can set 客户 on an existing document, so the only remedy is re-uploading with 客户 chosen.
- **原因**:client_visible and client_id are independent optional fields, but the shared visibility rule requires both. Nothing validates the pair, nothing derives client_id from job_id / the related record, and the flash + badge report success regardless.
- **修法**:Add `'client_id' => ['nullable','integer', Rule::exists('clients','id'), 'required_if:client_visible,1']` in DocumentController::store (with a zh message), derive client_id from job_id when a job is chosen, and in visibility() abort/warn when `$data['client_visible'] && $document->client_id === null`. Also grey out the 设为可见 button for rows whose client is "—".
- **工时**:约 1.5 小时 · 来源:static:Platform

### M30 · [Platform] No lang/zh/validation.php anywhere — every Platform validation failure prints English with raw DB column names

- **页面**:all Platform POST/PUT forms — /admin/documents, /admin/users, /jobs/create, /admin/webhooks, /register
- **受影响角色**:admin, customer_service, dispatcher, warehouse_supervisor, warehouse_operator, transport_operator, finance, client
- **复现**:Best (no client-side guard blocking it): log in as admin → /admin/users/create → fill 姓名 + 邮箱 + 密码(≥8) → set 角色 = 客户 → leave the 客户 select on "—" → 保存. Red banner shows the English "The client id field is required when role is client." instead of a zh message naming 客户. (The select at app/Modules/Platform/views/users/form.blade.php:29 has no `required`, so nothing stops the submit; the rule is `required_if:role,client` at app/Modules/Platform/Http/Controllers/UserController.php:87.)

Secondary, also reachable: /admin/webhooks → 名称 = t, 地址 = ftp://x, 事件 = * → 新增 → "The url field must start with https://, http://localhost, http://127.0.0.1." (input is type="url", which accepts an ftp scheme, so the browser submits it). And /register with two different passwords → "The password field confirmation does not match."

NOT valid as written by the reviewer: /admin/documents with 关联 ID empty — that input carries the HTML5 `required` attribute (app/Modules/Platform/views/documents/index.blade.php:15), so the browser blocks the submit and the server message is never reached. Use the users-form case instead.

Root cause and fix unchanged, but scope is app-wide, not Platform-only: there is no lang/zh/validation.php and there are no FormRequest classes anywhere, so all 114 validate()/Validator::make() sites in every module fall back to the vendor English file with raw request keys as :attribute.
- **原因**:The zh locale ships module strings only; Laravel falls back to the vendor English validation.php, and no custom :attribute names are declared, so raw request keys are echoed.
- **修法**:Add lang/zh/validation.php (copy the Laravel zh-CN translation) plus an `attributes` array mapping the Platform keys above to the labels already in lang/zh/platform.php (关联 ID, 关联类型, 客户可见, 客户, 公司名称, 联系电话, 确认密码, 事件, 地址). One file, no controller changes.
- **工时**:约 2 小时 · 来源:static:Platform

### M31 · [Platform] Create-user form preselects 角色 = 管理员 because no placeholder option is rendered

- **页面**:GET /admin/users/create → POST /admin/users — app/Modules/Platform/views/users/form.blade.php:19-25
- **受影响角色**:admin
- **复现**:Preconditions: log in as an admin (route group app/Modules/Platform/routes.php:61 is role:admin).

1. GET /admin/users -> click 新建用户 (GET /admin/users/create).
2. Before typing, view source on the 角色 `<select name="role">`: the first option is value="admin" and NO option carries `selected`; there is no `<option value="">` placeholder, unlike the 客户 select directly below it.
3. Fill only 姓名 = 测试仓管, 邮箱 = op-test@example.com, 密码 = password123. Do not open the 角色 dropdown (scroll past it, as a tester adding a warehouse operator would).
4. Press 保存. The browser does NOT block submission — `required` on a select only fires when the selected option is an empty-value placeholder, and the implicitly selected option here already has value="admin".
5. POST /admin/users carries role=admin; UserController::validated() accepts it via Rule::in(Enums::ROLES) and store() runs $user->syncRoles(['admin']).

Expected: submission rejected with a 角色 required error, or the field starts blank forcing an explicit choice.
Actual: redirect to /admin/users with the success flash, and the new row's 角色 column reads 管理员 — a full-privilege admin account created without the admin ever choosing a role. Nothing before the list page flags it.

Same trace on edit: open a user with no role assigned (GET /admin/users/{id}/edit, $currentRole null) and save without touching 角色 — the user is silently promoted to 管理员.
- **原因**:`required` on a <select> whose first option already has a non-empty value never fires; with no placeholder option and $currentRole null on create, 'admin' is the silent default.
- **修法**:Add `<option value="" @selected(old('role', $currentRole) === null)>—</option>` as the first option (the `required` attribute plus `Rule::in(Enums::ROLES)` then force an explicit choice). Same one-line change protects the edit page for users with no role assigned.
- **工时**:约 0.25 小时 · 来源:static:Platform

### M32 · [MasterData] Global search shows 客户 (client) hits to all 7 staff roles, but /admin/clients/{id}/edit only accepts admin|customer_service|finance — dispatcher / warehouse / transport users click and land on the 无权限 page

- **页面**:GET /admin/search (app/Modules/Platform/views/search/index.blade.php) → link target GET /admin/clients/{client}/edit (app/Modules/MasterData/views/clients/form.blade.php); hit produced by app/Modules/MasterData/MasterDataServiceProvider.php:21-23
- **受影响角色**:dispatcher, warehouse_supervisor, warehouse_operator, transport_operator
- **复现**:Setup: demo seed loaded (DemoFlowSeeder creates client EDWARD / "Edward Logistics").

A. Client hit (as reported)
1. Sign in as a 仓库主管 warehouse_supervisor (repeat with dispatcher, warehouse_operator, transport_operator — all four behave identically).
2. Go to /admin/search?q=EDWARD (or type EDWARD in the top-nav search box and press Enter).
3. Observed: the page renders a 客户 section with row `EDWARD · Edward Logistics` and meta `eom · per_job` — i.e. the client's payment terms and invoice mode are shown to a role barred from master data.
4. Click the `EDWARD · Edward Logistics` link (href = /admin/clients/<id>/edit).
5. Observed: 403 page 「您没有此操作的权限 … 有权限的角色:管理员 / 客服 / 财务」. No client screen exists that this role may open, so the hit is a pure dead end. Reproduces for every client the query matches and on every search these roles run.
6. Control: sign in as 客服 customer_service, repeat step 2-4 — the client edit form opens normally, proving the hit itself is valid and only the role gating is missing.

B. Same defect in Warehouse search (found while verifying; include in the fix)
1. Sign in as a 运输操作员 transport_operator.
2. Go to /admin/search?q=<any seeded ASN number> (also works for a container no., consignment mark, or stock unit label code).
3. Observed: a 仓库 section lists the ASN / container / stock-unit hit.
4. Click it (href = /admin/asns/<id> or /admin/stock/<id>).
5. Observed: the same 403 no-permission page — app/Modules/Warehouse/routes.php:22 allows admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance, excluding transport_operator.

Expected after fix: /admin/search must not offer a hit whose target URL the current user cannot open. Preferred fix is registry-level — add an optional roles list to SearchRegistry::register() (app/Support/Search/SearchRegistry.php:19) and have search() skip searchers the user does not satisfy — registering 'masterdata' with admin|customer_service|finance and 'warehouse' with its own route role list. Also drop payment_terms/invoice_mode from the client hit's meta (or keep only for admin/finance). Regression tests: as warehouse_operator, get('/admin/search?q=EDWARD') must not see 'Edward Logistics'; as transport_operator, a search for a seeded ASN number must not see the ASN hit.
- **原因**:SearchRegistry has no notion of authorisation: `SearchRegistry::search()` (app/Support/Search/SearchRegistry.php:40-46) calls every registered searcher for whoever opened /admin/search, and MasterDataServiceProvider registers its searcher for all users while the URL it emits is behind `role:admin|customer_service|finance`. Blade-side guard (`@role('admin|customer_service|finance')` in resources/views/layouts/nav/masterdata.blade.php:2) exists for the nav link but has no equivalent for search hits. Secondary effect: the `meta` string exposes the client's payment_terms and invoice_mode (billing data) to roles that are explicitly barred from master data.
- **修法**:Gate the searcher on the same roles as the routes. Either (a) return [] early inside the closure in app/Modules/MasterData/MasterDataServiceProvider.php:21 when the current user cannot open master data, e.g. `fn (string $q): array => auth()->user()?->hasAnyRole(['admin','customer_service','finance']) ? Client::query()... : []`, or (b) add an optional `roles` argument to SearchRegistry::register() and have SearchRegistry::search() skip searchers the user does not satisfy (better — the same trap exists for any future role-restricted module). Also drop payment_terms/invoice_mode from `meta`, or keep them only for the finance/admin case. Add a regression test alongside ClientCrudTest::test_master_data_is_for_admin_customer_service_and_finance_only asserting `get('/admin/search?q=EDWARD')` as warehouse_operator does not see the client hit.
- **工时**:约 1.5 小时 · 来源:static:MasterData

### M33 · [MasterData] No lang/zh/validation.php — every MasterData form error prints English with raw snake_case field names (payment_terms, default_markup_percent, leg_type) even though Chinese labels for those exact fields already exist

- **页面**:POST /admin/clients, PUT /admin/clients/{client} (app/Modules/MasterData/views/clients/form.blade.php); POST/PUT /admin/suppliers (suppliers/form.blade.php); POST/PUT /admin/carriers (carriers/form.blade.php)
- **受影响角色**:admin, customer_service, finance
- **复现**:Sign in as 管理员 (admin) and open /admin/clients/create.

A) Field-name case: in 编码 type `ACME LOGISTICS` (with a space), in 名称 type `Acme`, leave everything else at defaults (业务类型=头程 + 尾程, 状态=启用, 账期=eom, 默认加成 %=0), press 保存. The browser lets it through — the code input has maxlength=20 but no pattern. The page returns with the red flash box (resources/views/layouts/partials/flash.blade.php:4-11) showing English: "The code field must only contain letters, numbers, dashes and underscores." on a fully Chinese form whose label reads 编码.

B) Missing max + English message: correct 编码 to `ACME`, set 默认加成 % to `1000`, press 保存. The number input (form.blade.php:75) has step/min but no max attribute, so the browser submits it; server rule max:999.99 fires and the flash box shows "The default markup percent field must not be greater than 999.99." — English, naming `default markup percent` while the on-screen label is 默认加成 %. That English string is the only place the 999.99 ceiling is ever disclosed.

C) Repeat B reusing an existing client code to see "The code has already been taken."

Tester note — do NOT use the reviewer's `net_1000` in 账期: that input carries pattern="prepaid|eom|net_\d{1,3}", so the browser blocks it and no server message appears. Same for 联系邮箱/账单邮箱 (type=email) and the enum selects (业务类型, 开票模式, 状态, 发运截单时间 via type=time). Only 编码 and 默认加成 % expose server-side messages through the normal UI.

Expected after fix: add lang/zh/validation.php with translated rule strings plus an `attributes` map reusing lang/zh/masterdata.php fields.* (编码 / 账期 / 默认加成 % / 业务类型 / 发运截单时间 …), and add max="999.99" to the markup input at app/Modules/MasterData/views/clients/form.blade.php:75.
- **原因**:The project translates all UI strings per module (AGENTS.md: no hardcoded Chinese in Blade) but never published a zh validation catalogue, so Laravel's Translator falls back to the en locale for the `validation.*` namespace. MasterData's forms are recoverable (all inputs use old(), and the errors are displayed) — this is a comprehension defect, not a blocker.
- **修法**:Add lang/zh/validation.php: the translated rule messages plus an `attributes` array mapping code/name/abn/leg_type/contact_name/contact_phone/contact_email/billing_email/address/suburb/state/postcode/status/payment_terms/invoice_mode/invoice_period/invoice_grouping/default_markup_percent/dispatch_cutoff_time to the strings already in lang/zh/masterdata.php `fields.*` (the same file serves every module's flat field names). While there, add `max="999.99"` to the markup input in app/Modules/MasterData/views/clients/form.blade.php:75 and a `title` to the payment_terms input at line 50 so the browser-level rejection is also explained.
- **工时**:约 2.5 小时 · 来源:static:MasterData

### M34 · [Reports] 报表 filter form loses every input after a validation error (no old()) — the staff 客户报表 page drops the selected client and falls back to "请选择客户"

- **页面**:GET /reports/client (staff 客户视角) and GET /reports, GET /portal/reports — view app/Modules/Reports/views/partials/filters.blade.php (included by app/Modules/Reports/views/client.blade.php:9 and app/Modules/Reports/views/index.blade.php:10)
- **受影响角色**:admin, finance, customer_service, dispatcher, client
- **复现**:Precondition: a client such as "Alpha Pty Ltd" exists in master data. 1) Log in as customer-service@erp.local (role customer_service) — admin/finance/dispatcher behave identically. 2) In the top nav open 报表 → 客户报表. Confirm the address bar shows exactly /reports/client with NO query string (this is the load-bearing precondition: it is what gets stored as the session's previous URL). 3) In the filter bar pick 客户 = Alpha Pty Ltd, set 从 = 2026-09-10, set 到 = 2026-09-01 (reversed range, i.e. a one-character typo in the month/day). 4) Click 查询. Observed: the browser is redirected back to the bare /reports/client (no query string in the address bar). The red error banner shows the 到 must be a date after or equal to 从 message, but the 客户 select has snapped back to "— 选择客户 —", 从/到 have snapped back to the current month (2026-09-01 / 2026-09-30), and the body shows 请选择客户后查看报表. All three filter values must be re-entered from scratch. Expected: the form redisplays the submitted client_id/from/to (Laravel flashes them via withInput; the partial simply never reads old()). Variants: on /reports (admin or finance) and on /portal/reports (a client user) the same reversed-date submit loses both dates back to the current month — the client picker is absent there. Contrast case showing the milder path: from the reset page, run one VALID query first (client + 2026-09-01..2026-09-30, URL now carries the query string), then submit a reversed range — you are bounced back to the previous valid URL, so the old filters reappear and only the just-typed values are lost.
- **原因**:The filter partial renders the *server-resolved* state ($period, $client) instead of the flashed old input. Laravel's redirect on a failed GET validation goes back to the previously stored URL (StartSession only stores non-redirect GET URLs), which for a nav-click entry is the bare /reports/client, so both the query string and the un-read old() input are lost.
- **修法**:In app/Modules/Reports/views/partials/filters.blade.php use the flashed input as the default: `@selected((int) old('client_id', $client?->id) === $option->id)`, `value="{{ old('from', $period->from->toDateString()) }}"`, `value="{{ old('to', $period->to->toDateString()) }}"`. (Optionally have the two report controllers redirect back to their own route with the submitted query instead of url()->previous().)
- **工时**:约 1.5 小时 · 来源:static:Reports

### M35 · [Reports] Filter validation failures print Laravel's English message with raw field names (from / to / client_id) on a Chinese-only UI — there is no lang/zh/validation.php and no attribute map

- **页面**:GET /reports, GET /reports/client, GET /reports/client/export/{table}, GET /portal/reports — banner rendered by resources/views/layouts/partials/flash.blade.php:4-11
- **受影响角色**:admin, finance, customer_service, dispatcher, client
- **复现**:Log in as finance@erp.local and open GET /reports (老板视角). The filter bar (app/Modules/Reports/views/partials/filters.blade.php) renders two `<input type="date" required>` named `from` (label 从) and `to` (label 到), with no `min` attribute on `to`, so the browser accepts an out-of-order range. Set 从 = 2026-09-10, 到 = 2026-09-01 and click 查询 → GET /reports?from=2026-09-10&to=2026-09-01. BossReportController.php:22 runs `$request->validate(ReportPeriod::rules())`, the `after_or_equal:from` rule on `to` fails, and Laravel redirects back; resources/views/layouts/partials/flash.blade.php:4-11 prints `$errors->all()` verbatim, giving the English string "The to field must be a date after or equal to from." on an otherwise all-Chinese page (labels 从/到). Same on GET /reports/client (ClientReportController.php:27) and on the client portal GET /portal/reports as a client user (PortalReportController.php:23,44) — identical form partial, identical rules.

Second, lower-value path: hand-edit/bookmark GET /reports/client/export/orders_by_client without client_id → "The client id field is required." (ClientReportController.php:50; asserted as a session error key by tests/Feature/Reports/ReportsTest.php:135). Not reachable by clicking, since the export link is built with client_id and the picker is `required`, so treat the date-range case as the real repro.

Verified cause: `find` over the repo (vendor excluded) returns no validation.php anywhere; lang/ contains only zh/ with billing, masterdata, orders, platform, portal, reports, transport, warehouse; no 'attributes' block exists (only domain-specific 'validation' sub-arrays in orders.php/portal.php, used via explicit __() calls); config/app.php:81 locale=zh, fallback en; no Validator macro, custom messages() or attribute map anywhere in app/ or bootstrap/app.php. So every framework rule message falls back to English app-wide — Reports is just the easiest place to see it. Cosmetic only: the data is not corrupted, the user can correct the dates and re-submit, and ReportPeriod::fromInput clamps to>=from anyway once validation passes.
- **原因**:No zh translation file for validation messages/attribute names exists, so every failed filter submission surfaces framework English plus the raw request key.
- **修法**:Add lang/zh/validation.php (可从 vendor 的 en/validation.php 翻译) and an 'attributes' block mapping at least from → 开始日期, to → 结束日期, client_id → 客户; the Reports module needs no code change once the file exists.
- **工时**:约 2 小时 · 来源:static:Reports

### M36 · [Reports] A date range longer than 366 days is silently truncated — the page and the exported CSV cover a different period than the user asked for, with no message

- **页面**:GET /reports (老板视角) and GET /reports/export/{table}; same on /reports/client and /portal/reports — app/Modules/Reports/Services/ReportPeriod.php
- **受影响角色**:admin, finance, customer_service, dispatcher, client
- **复现**:1. Log in as finance@erp.local (or admin) and open GET /reports. 2. In the filter form set 从 = 2025-01-01 and 到 = 2026-09-10 (617 days), click 查询. 3. Expected: a validation error such as "统计期间最长 366 天". Actual: validation passes (both valid dates, to >= from), the page reloads, the 到 input now shows 2026-01-02 (rewritten by the clamp at app/Modules/Reports/Services/ReportPeriod.php:29-31), and the page shows "统计期间: 2025-01-01 ~ 2026-01-02" with no error, warning or flash; the hint text above the form (lang/zh/reports.php:12) never mentions a cap. 4. Click 导出 CSV on 费用汇总: the download is report-financials-20250101-20260102.csv, covering the truncated period. 5. Bypassing the form: GET /reports/export/financials?from=2025-01-01&to=2026-09-10 returns HTTP 200 with the 2025-01-01..2026-01-02 file. 6. Repeat as customer_service on /reports/client?client_id=<id>&from=2025-01-01&to=2026-09-10 and as a client user on /portal/reports with the same dates — identical silent truncation (ClientReportController.php:28/:51, PortalReportController.php:23/:44). Note: page label, table data and CSV filename all agree with the truncated range, so no data is mislabelled; the only defect is that the user is never told the requested range was shortened.
- **原因**:The 366-day limit is enforced by silently mutating the period inside fromInput() instead of by a validation rule that tells the user, so the UI accepts an input it does not honour.
- **修法**:Move the span limit into validation — e.g. add `'to' => [..., 'before_or_equal:'.<from+366>]` via a closure rule (or a Rule object) in ReportPeriod::rules() with a zh message such as '统计期间最长 366 天', and drop the silent clamp in fromInput() (keep it only as a defence for programmatic callers).
- **工时**:约 1 小时 · 来源:static:Reports

### M37 · [Orders] 确认订单 button is rendered for every staff role but only admin/customer_service/dispatcher may confirm → finance / warehouse roles get the no-permission page

- **页面**:GET /orders/{order} (status received) → app/Modules/Orders/views/show.blade.php lines 36-44; POST orders.confirm → OrderController@confirm
- **受影响角色**:finance, warehouse_supervisor, warehouse_operator, transport_operator
- **复现**:1. Log in to the platform UI as finance@erp.local (repeat with warehouse-supervisor@erp.local, warehouse-operator@erp.local, transport-operator@erp.local).
2. Go to /orders and open any order whose 操作状态 is 已接收 / received (e.g. an ASN-generated Edward order, or any PDF-draft order — those show the draft banner right above the button).
3. On GET /orders/{id} the 确认订单 button renders (show.blade.php:36-44), identical to what admin sees; nothing on the page indicates it is unavailable.
4. Click 确认订单 -> POST /orders/{id}/confirm -> HTTP 403 no-permission page ("allowed roles: admin, customer_service, dispatcher"); the order stays in 已接收 and the user must navigate back manually.
5. Control: as admin@erp.local / customer-service@erp.local / dispatcher@erp.local the same click returns 302 back with the confirmed flash and the status moves to 已确认.
6. Contrast check on the same page proving it is an oversight: as finance, the 改装尾板 (tailgate) form at show.blade.php:137 and the coordinator line-edit block at :186 are correctly hidden for the same role set — only the confirm form is missing its guard.
- **原因**:Blade condition checks only order state; the controller's RequiredRoles check is not mirrored in the view.
- **修法**:Wrap the confirm form in `@role('admin|customer_service|dispatcher')` (or `@if (auth()->user()->hasAnyRole([...]))` reusing a constant shared with authorizeOrderEntry) and show a read-only hint for other roles.
- **工时**:约 0.5 小时 · 来源:dynamic:harness

### M38 · [Transport] Transport nav links 司机任务 / 承运商对账 / 配送班次 are shown to every staff role but open to 403 for most of them

- **页面**:resources/views/layouts/nav/transport.blade.php → GET /driver (DriverController@index), /transport/carrier-invoices (CarrierInvoiceController@index), /transport/runs (DeliveryRunController@index)
- **受影响角色**:admin, customer_service, dispatcher, warehouse_supervisor, warehouse_operator, finance
- **复现**:Seed demo users (each has exactly one role, PlatformSeeder.php:39). 1) Log in as admin@erp.local, open the 运输 dropdown (it always renders because transport.index is ungated), click 司机任务 → GET /driver → 403 (DriverController::authorizeDriver requires hasRole('transport_operator') only; bare 403 page, no role list because it uses abort_unless rather than RequiredRoles). Repeat as customer-service@, dispatcher@, warehouse-supervisor@, warehouse-operator@, finance@ → all 403. 2) As customer-service@ / dispatcher@ / warehouse-supervisor@ / warehouse-operator@, click 承运商对账 → GET /transport/carrier-invoices → 403 (allowed: admin, transport_operator, finance). 3) As warehouse-supervisor@ / warehouse-operator@ / finance@, click 配送班次 → GET /transport/runs → 403 (allowed: admin, customer_service, dispatcher, transport_operator). Contrast: the 运输 dropdown for a warehouse_operator shows five links of which only two (运输 index, 异常) are openable, while the 仓储 dropdown correctly hides links the role cannot open.
- **原因**:The Transport nav include renders the same five links for every non-client user while the three controllers restrict to different role sets.
- **修法**:Wrap each link in the matching @role: driver → `@role('transport_operator')`; carrier-invoices → `@role('admin|transport_operator|finance')`; runs → `@role('admin|customer_service|dispatcher|transport_operator')` (compare the Warehouse include which already does this). Optionally let admin open /driver read-only.
- **工时**:约 0.5 小时 · 来源:dynamic:harness

### M39 · [Billing] 未开票 page: 生成 Job 发票 button is offered for Jobs whose only unbilled charges are storage, and always fails (English message)

- **页面**:GET /billing/unbilled → app/Modules/Billing/views/invoices/unbilled.blade.php line 24; POST billing.invoices.draft_job → InvoiceController@draftJob → InvoiceService::draftForJob
- **受影响角色**:finance, admin
- **复现**:Prereq: weekly storage billing has run (demo seeder does this: DemoFlowSeeder::snapshots() → StorageBillingService::billWeek(today()->subWeek())) so at least one client has unbilled storage charges for a Job with no unbilled non-storage charges (e.g. any client other than EDWARD, whose storage week is invoiced by the seeder).
1. Log in as finance@erp.local (or admin) — route group role:admin|finance, app/Modules/Billing/routes.php:22.
2. GET /billing/unbilled. Every job row in every client block shows a 生成发票 (draft_job) button — unbilled.blade.php:24 renders the form unconditionally.
3. Pick a job row whose lines are all storage charges (its client block also shows the 周储存发票 form, and the row disappears from the pool only after a storage invoice). Click 生成发票 → POST /billing/invoices/job/{job}.
4. Observed: redirected back to /billing/unbilled with errors[invoice] = "No unbilled charges on this Job." — English text inside a zh UI (config/app.php locale=zh). No invoice is created, and the button can never succeed for that row.
Expected: the per-Job button should not be offered for rows with no unbilled service charges (only the 周储存发票 / period-scope=storage path applies), and any error must come from lang/zh/billing.php.
Working alternative (why this is not blocking): on the same page the 周储存发票 form (client_id + week) drafts the storage invoice successfully, and the period form with scope=储存/全部 also works.
- **原因**:The pool counts storage charges per job, but per-Job drafting excludes storage by design (storage goes on the weekly storage invoice); the view does not distinguish, and the service message is hard-coded English.
- **修法**:In InvoiceController::unbilled compute per-job service vs storage counts and only render the per-Job button when service charges exist (show the 周储存发票 button otherwise); translate the InvalidArgumentException messages via lang/zh/billing.php (`__('billing.invoices.no_unbilled_job')` etc.) in tryDraft.
- **工时**:约 1 小时 · 来源:dynamic:harness

### M40 · [Orders] Order create / manifest import: choosing a Job that belongs to another client aborts with a bare 422 error page and discards the whole form

- **页面**:GET /orders/create (views/form.blade.php lines 35-41) → POST orders.store (OrderController.php:246-253); GET /orders/imports/create → POST orders.imports.preview (OrderImportController.php:56)
- **受影响角色**:admin, customer_service, dispatcher
- **复现**:Precondition: at least two active clients, each owning at least one non-cancelled Job.

A) Order create (job optional):
1. Log in as customer-service@erp.local (or any admin/dispatcher).
2. GET /orders/create (新建订单).
3. In 客户 pick Client A. The 关联 Job dropdown still lists every job of every client as "J-xxx — <client name>" — no filtering, no disabled options.
4. Fill the whole form: 收货人/地址/州/邮编/地址类型, 需求日期, 服务级别, and at least one goods line (描述, 包装类型, 箱数).
5. In 关联 Job pick a job belonging to Client B.
6. Submit.
Expected: form redisplays with a field-level error on 关联 Job and input preserved.
Actual: full-page navigation to Laravel's generic error page '422 所选Job不属于所选客户。' (OrderController.php:252); Back gives a resubmission prompt or an empty form and all goods lines are lost.

B) Manifest import (job required, worse):
1. Same login, GET /orders/imports/create (导入清单).
2. Pick Client A; the Job select is required and shows only job numbers, with no client name to disambiguate.
3. Pick a job owned by Client B, set 需求日期 and 服务级别, attach a valid .csv/.xlsx manifest, submit.
Actual: same generic 422 page (OrderImportController.php:56); the uploaded file and all fields are lost and must be re-entered.

Reference implementation already in repo: DraftOrderController's client-scoped Rule::exists plus app/Modules/Orders/views/drafts/create.blade.php:17 and its JS at lines 46-50.
- **原因**:Cross-field rule implemented with abort() instead of a validation error; job list not scoped to the chosen client.
- **修法**:Replace abort(422) with `throw ValidationException::withMessages(['job_id' => __('orders.validation.job_client_mismatch')])` (redirects back with old input), add `data-client-id` to job options and reuse the drafts page JS filter, or use `Rule::exists('jobs','id')->where('client_id', $request->integer('client_id'))` as DraftOrderController.php:38 does.
- **工时**:约 1 小时 · 来源:dynamic:harness

### M41 · [Platform] Approvals page shows 批准 / 拒绝 to the person who raised the request; the service then refuses with an English PLT-7 message

- **页面**:GET /admin/approvals → app/Modules/Platform/views/approvals/index.blade.php lines 29-35; POST platform.approvals.approve|reject → ApprovalService::approve
- **受影响角色**:admin, finance
- **复现**:1. Log in as admin@erp.local (or any finance user). 2. Go to /admin/billing/rate-cards, open any draft card (/admin/billing/rate-cards/{card}) and click 申请启用 (POST billing rate_cards.request_activation) — this creates a pending approval of type rate_card_change with requested_by = your own user id. 3. Open 审批 (GET /admin/approvals; default filter status=pending). Your own request row shows 批准 and 拒绝 buttons alongside 撤回, because app/Modules/Platform/views/approvals/index.blade.php:29-32 gates the two decision forms on @role('admin|finance') only, with no `$a->requested_by !== auth()->id()` condition (contrast the 撤回 form at l.33 which does check it). 4. Click 批准 (POST /admin/approvals/{id}/approve). The role middleware at app/Modules/Platform/routes.php:56 passes, ApprovalController::decide (app/Modules/Platform/Http/Controllers/ApprovalController.php:53-62) validates only `note`, and ApprovalService::decide (app/Modules/Platform/Services/ApprovalService.php:67) throws. 5. Observe: redirected back to 审批 with a red flash rendered by resources/views/layouts/partials/flash.blade.php reading, in English on an otherwise Chinese page, "A request must be decided by a second person (PLT-7)." 6. Same for 拒绝 (POST /admin/approvals/{id}/reject). The approval stays pending; no state change. Expected: the requester should see a muted 等待第二人审批 label instead of two buttons that can never succeed.
- **原因**:View renders decision buttons on role alone; four-eyes rule only enforced in the service, with an untranslated message.
- **修法**:Add `&& $a->requested_by !== auth()->id()` to the approve/reject condition and show a muted '等待第二人审批' label instead; move the message to lang/zh/platform.php.
- **工时**:约 0.5 小时 · 来源:dynamic:harness

### M42 · [Warehouse] Completion buttons offered before their preconditions are met (return receipt 完成收货 / 完成检验, stocktake 关闭盘点, dispatch of a held order) — every refusal is an untranslated English sentence

- **页面**:GET /warehouse/returns/{receipt} (views/returns/show.blade.php lines 44-48), GET /warehouse/stocktakes/{stocktake} (views/stocktakes/show.blade.php line 55), GET /warehouse/outbound (views/outbound/index.blade.php line 75)
- **受影响角色**:warehouse_operator, warehouse_supervisor, admin
- **复现**:Login as warehouse_supervisor (or admin/warehouse_operator).
(a) GET /warehouse/returns/{receipt} for a receipt in status 'expected' where at least one line still shows '—' in the 收货数量 column (received_at NULL). The bottom button '收货完成,进入验收' is rendered unconditionally. Click it → 302 back, flash error bar shows the literal English 'Every line must be received (0 is allowed) before inspection starts.' (error key: receipt).
(b) Same page for a receipt in status 'received' (badge 已收货,验收中) with at least one line whose 处置 column is '—' (inspected_at NULL). Button '验收完成' is rendered on status alone. Click it → back with English 'Every line needs a disposition first.' (error key: receipt).
(c) GET /warehouse/stocktakes/{stocktake} for a stocktake in status 'counting' with at least one line never counted. Button '提交调整并关闭' is rendered on status alone. Click it → back with 'Lines not counted yet: 1' — English plus a raw stocktake_lines.id the operator cannot map to a row (error key: close).
(d) Put an order under an active financial hold (hold_type=financial), then pick and pack one of its fulfilments (permitted by design). GET /warehouse/outbound: the packed fulfilment appears in 待发运 with a fully enabled 发运交接 form (pallet_count / handed_to / shipment_id) and no hold marker. Submit → back with 'This order is under a financial hold — release it before dispatch.' (error key: pallet_count).
Expected in all four: the affordance is suppressed or annotated with the missing precondition, and any refusal renders in Chinese from lang/zh/warehouse.php per ERP_PLAN §语言 / AGENTS.md.
- **原因**:Precondition checks exist only in services and throw English InvalidArgumentException text that is echoed to the user; views do not disable the button or explain the missing step.
- **修法**:Disable the button (with a zh hint) when lines are un-received / un-dispositioned / uncounted, mark held fulfilments on the board (`hasActiveFinancialHold()`), and move the four messages into lang/zh/warehouse.php keys used by the controllers' catch blocks.
- **工时**:约 1 小时 · 来源:dynamic:harness

### M43 · [Warehouse] Scan-gun forms lose the typed value after a validation error (no old()) — stock move, putaway, new location, stocktake scan

- **页面**:views/stock/show.blade.php lines 37 & 58 (POST stock.move / quarantine), views/putaway/index.blade.php line 21 (POST putaway.store), views/locations/index.blade.php lines 22-25 (POST locations.store), views/stocktakes/show.blade.php scan form
- **受影响角色**:warehouse_operator, warehouse_supervisor, admin
- **复现**:Log in as warehouse_operator (or supervisor/admin). A) Stock move: open any stock unit page /warehouse/stock/{id}. In the 移库 card type an unknown code MEL-ZZ-99-99 into the first field and "test reason" into the reason field, submit. Error "库位 MEL-ZZ-99-99 不存在或不属于该仓库。" is shown and BOTH inputs are empty again (app/Modules/Warehouse/views/stock/show.blade.php:37-38; StockController::move returns back()->withErrors() with no ->withInput()). B) Same on the 隔离恢复 card for a non-good unit (show.blade.php:57-58). C) Putaway: /warehouse/putaway, in any row type MEL-ZZ-99-99 and submit — row input empties (putaway/index.blade.php:21; PutawayController::store, no withInput). D) New location: /warehouse/locations, in the 新建库位 form enter zone "A-1" (contains a hyphen, fails alpha_num), aisle 01, bin 01, submit — English message "The zone field must only contain letters and numbers." and all three fields are blank even though Laravel flashed the input, because locations/index.blade.php:23-25 never call old(). E) Stocktake: open an open stocktake /warehouse/stocktakes/{id}, type an unknown label code and a qty in the scan form, submit — both fields empty (stocktakes/show.blade.php:16-17; StocktakeController::scan, no withInput). Expected in all cases: the typed value is preserved so the operator can correct one character instead of re-scanning/re-typing.
- **原因**:Inputs rendered without `value="{{ old(...) }}"`; on scan pages with many rows old() is per-page not per-row, so the value is dropped.
- **修法**:Add `value="{{ old('location_code') }}"` / `old('zone')` etc. (for multi-row pages key old() by unit id or redirect with `withInput()` and highlight the failing row).
- **工时**:约 1 小时 · 来源:dynamic:harness

### M44 · [Platform] No lang/zh/validation.php and no attribute names: every Laravel rule failure prints English with raw field names (units.1.carton_qty, rows.0.description, threshold_json, positions, zone…)

- **页面**:All forms using $request->validate() (lang/zh contains only module files; resources/views/layouts/partials/flash.blade.php prints $errors->all())
- **受影响角色**:admin, customer_service, dispatcher, warehouse_supervisor, warehouse_operator, transport_operator, finance, client
- **复现**:Deterministic path (admin or warehouse_supervisor): 仓库 → 库位 (locations index, app/Modules/Warehouse/views/locations/index.blade.php). In the 新建库位 form type a zone of `A 1` (letter, space, digit — passes the browser's maxlength/required), fill aisle/bin, submit. The red flash bar renders `The zone field must only contain letters and numbers.` — English, with the raw field name `zone` instead of the zh label already present in lang/zh/warehouse.php. Second, role-independent path (finance/admin): 费用 → 费率卡 → open a rate card → in the 新增费率行 form put `25` (not JSON) in the thresholds text box (rate_cards/show.blade.php:59) and save → `The threshold json field must be a valid JSON string.` Third (transport_operator, no JS): open the driver POD page and submit without drawing a signature — the hidden `signature_data` input is not covered by HTML5 `required`, so the server answers `The signature data field is required.` Same class of output on any of the 110 plain `$request->validate()` endpoints, e.g. unplanned receiving with only 唛头 typed on a row → `The rows.0.description field is required.`
- **原因**:Framework validation language file never added for zh; field names are not mapped to the zh labels already present in the module lang files.
- **修法**:Add lang/zh/validation.php (Laravel-Lang zh_CN as base, including the `attributes` array mapping every form field — with wildcard entries such as 'units.*.carton_qty' => '箱数' — and `custom` messages), and register it in contracts/ as a frozen-zone change. Estimate covers the file plus attribute mapping for the ~60 fields seen by the harness.
- **工时**:约 3 小时 · 来源:dynamic:harness

## 覆盖

| 检查 | 表单数 | 被反驳的发现 |
|---|---|---|
| static:Warehouse | 40 | 2 |
| static:Orders | 31 | 1 |
| static:Portal | 18 | 0 |
| static:Transport | 19 | 1 |
| static:Billing | 27 | 2 |
| static:Platform | 30 | 1 |
| static:MasterData | 19 | 0 |
| static:Reports | 11 | 0 |
| dynamic:harness | 8 | 2 |

5 条发现的复核请求因 API 安全过滤失败而未完成复核,按未确认处理,不在上表中。