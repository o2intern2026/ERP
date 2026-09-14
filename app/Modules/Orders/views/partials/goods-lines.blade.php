{{-- Items 5 + 7 (tester feedback): compact goods-line table shared by orders::form and portal::orders.create — one line per row with
     包装类型 / 箱数 / 重量 / 长 / 宽 / 高 (staff also 件数 / CBM); rows are added from the <template> by vanilla JS and spare rows are
     pruned server side (OrderFormRows). Labels come from the caller's lang file ($prefix); the CSS is inline (CHANGE_REQUESTS #80). --}}
@php
    $prefix = $prefix ?? 'orders';
    $extended = $extended ?? false;
    $rows = array_values(array_filter((array) old('lines', []), 'is_array')) ?: [['package_type' => 'carton']]; // no pre-filled 箱数: a typed quantity is content (OrderFormRows)
@endphp
<p class="text-muted"><small>{{ __('orders.lines.hint') }}</small></p>
<div class="overflow-auto">
    <table class="form-rows" id="goods-lines">
        <thead><tr>
            <th>{{ __($prefix.'.fields.description_cn') }}</th>
            <th>{{ __($prefix.'.fields.description_en') }}</th>
            <th>{{ __($prefix.'.fields.package_type') }}</th>
            <th>{{ __($prefix.'.fields.carton_qty') }}</th>
            @if ($extended)<th>{{ __('orders.fields.unit_qty') }}</th>@endif
            <th>{{ __('orders.lines.unit_weight') }}</th>
            <th>{{ __('orders.lines.weight_total') }}</th>
            <th>{{ __($prefix.'.fields.length_mm') }}</th>
            <th>{{ __($prefix.'.fields.width_mm') }}</th>
            <th>{{ __($prefix.'.fields.height_mm') }}</th>
            @if ($extended)<th>{{ __('orders.fields.cbm') }}</th>@endif
            <th></th>
        </tr></thead>
        <tbody>
            @foreach ($rows as $index => $line)
                @include('orders::partials.goods-line-row', ['index' => $index, 'line' => $line, 'first' => $index === 0, 'extended' => $extended, 'prefix' => $prefix])
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="{{ $extended ? 12 : 10 }}"><small class="text-muted" id="goods-lines-total" data-template="{{ __('orders.lines.totals', ['qty' => ':qty', 'kg' => ':kg']) }}">{{ __('orders.lines.totals', ['qty' => 0, 'kg' => 0]) }}</small></td></tr></tfoot>
    </table>
</div>
<button type="button" class="secondary outline form-rows-add" id="add-goods-line">{{ __('orders.actions.add_line') }}</button>
<template id="goods-line-template">@include('orders::partials.goods-line-row', ['index' => '__INDEX__', 'line' => [], 'first' => false, 'extended' => $extended, 'prefix' => $prefix])</template>
<script>
    (() => {
        // Item 7: "add line" clones the <template> row with the next free index; removing the only row just clears it.
        const wire = (tableId, templateId, buttonId, rowClass) => {
            const body = document.querySelector('#' + tableId + ' tbody');
            const template = document.getElementById(templateId);
            if (!body || !template) return;
            const next = () => Math.max(-1, ...Array.from(body.querySelectorAll('tr')).map(tr => Number(tr.dataset.index) || 0)) + 1;
            document.getElementById(buttonId).addEventListener('click', () => {
                body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next())));
                body.lastElementChild.querySelector('input, select')?.focus();
            });
            body.addEventListener('click', event => {
                const button = event.target.closest('button.remove-row');
                if (!button) return;
                const row = button.closest('tr');
                if (body.querySelectorAll('tr.' + rowClass).length > 1) row.remove();
                else row.querySelectorAll('input').forEach(input => { input.value = ''; });
                body.dispatchEvent(new Event('change', { bubbles: true })); // lets the tailgate helper recompute
            });
        };
        wire('goods-lines', 'goods-line-template', 'add-goods-line', 'goods-line');
        wire('declared-packages', 'declared-package-template', 'add-declared-package', 'declared-package');

        // 2026-09-14 lead feedback ("申报包裹和货物明细的重量应该自动对应和计算"):
        //   (a) each goods line carries 单件重量 ⇄ 整行合计, converted through 箱数 whichever one the person types;
        //   (b) with 按货物明细自动生成申报包裹 ticked (default), the declared packages mirror the goods lines — one package
        //       row per goods line (type, qty = 箱数, 单件重量, dims), kept read-only and rebuilt on every edit — so a
        //       提货直送 order is described once; untick to type the packages by hand. Footers show the live totals.
        const linesBody = document.querySelector('#goods-lines tbody');
        const pkgBody = document.querySelector('#declared-packages tbody');
        const pkgTemplate = document.getElementById('declared-package-template');
        const follow = document.getElementById('packages-follow-lines');
        const addPackage = document.getElementById('add-declared-package');
        const form = linesBody?.closest('form');
        if (!linesBody || !form) return;
        const field = (row, suffix) => row.querySelector('[name$="' + suffix + '"]');
        const num = (el) => { const v = parseFloat(el?.value); return Number.isFinite(v) ? v : 0; };
        const round3 = (v) => String(Math.round(v * 1000) / 1000);
        const lineRows = () => Array.from(linesBody.querySelectorAll('tr.goods-line'));
        const pkgRows = () => Array.from(pkgBody?.querySelectorAll('tr.declared-package') ?? []);

        const syncLine = (row, source) => {
            const qty = Math.floor(num(field(row, '[carton_qty]')));
            const unit = row.querySelector('.unit-weight'), total = row.querySelector('.line-weight');
            if (!unit || !total) return;
            if (source === unit) { if (unit.value === '') total.value = ''; else if (qty >= 1) total.value = round3(num(unit) * qty); }
            else if (source === total) { if (total.value === '') unit.value = ''; else if (qty >= 1) unit.value = round3(num(total) / qty); }
            else if (qty >= 1) { if (unit.value !== '') total.value = round3(num(unit) * qty); else if (total.value !== '') unit.value = round3(num(total) / qty); }
        };
        const totals = () => {
            const lt = document.getElementById('goods-lines-total');
            if (lt) {
                let qty = 0, kg = 0;
                lineRows().forEach(r => { qty += Math.floor(num(field(r, '[carton_qty]'))); kg += num(r.querySelector('.line-weight')); });
                lt.textContent = lt.dataset.template.replace(':qty', String(qty)).replace(':kg', round3(kg));
            }
            const pt = document.getElementById('declared-packages-total');
            if (pt) {
                let qty = 0, kg = 0;
                pkgRows().forEach(r => { const n = Math.floor(num(field(r, '[qty]'))); qty += n; kg += n * num(field(r, '[weight_kg]')); });
                pt.textContent = pt.dataset.template.replace(':qty', String(qty)).replace(':kg', round3(kg));
            }
        };
        const lock = (row, on) => {
            row.querySelectorAll('input').forEach(el => { el.readOnly = on; el.tabIndex = on ? -1 : 0; });
            row.querySelectorAll('select').forEach(el => { el.style.pointerEvents = on ? 'none' : ''; el.tabIndex = on ? -1 : 0; el.setAttribute('aria-readonly', on ? 'true' : 'false'); });
            const remove = row.querySelector('button.remove-row'); if (remove) remove.hidden = on;
        };
        const mirror = () => {
            if (!pkgBody || !pkgTemplate || !follow) return;
            const on = follow.checked;
            if (addPackage) addPackage.hidden = on;
            if (!on) { pkgRows().forEach(r => lock(r, false)); return; }
            const lines = lineRows(), want = Math.max(1, lines.length);
            let rows = pkgRows();
            while (rows.length > want) rows.pop().remove();
            while (rows.length < want) {
                const next = Math.max(-1, ...rows.map(tr => Number(tr.dataset.index) || 0)) + 1;
                pkgBody.insertAdjacentHTML('beforeend', pkgTemplate.innerHTML.replaceAll('__INDEX__', String(next)));
                rows = pkgRows();
            }
            rows.forEach((pr, i) => {
                const lr = lines[i];
                const set = (suffix, value) => { const el = field(pr, suffix); if (el) el.value = value; };
                set('[package_type]', lr ? field(lr, '[package_type]').value : 'carton');
                set('[qty]', lr ? field(lr, '[carton_qty]').value : '');
                set('[weight_kg]', lr ? lr.querySelector('.unit-weight').value : '');
                ['length_mm', 'width_mm', 'height_mm'].forEach(f => set('[' + f + ']', lr ? field(lr, '[' + f + ']').value : ''));
                lock(pr, true);
            });
        };
        const refresh = () => { mirror(); totals(); };
        form.addEventListener('input', (e) => {
            const row = e.target.closest?.('tr.goods-line');
            if (row) {
                syncLine(row, e.target);
                refresh();
                linesBody.dispatchEvent(new Event('change', { bubbles: true })); // the tailgate helper re-reads the line total we just set
                return;
            }
            refresh();
        });
        form.addEventListener('change', refresh);
        form.addEventListener('click', (e) => { if (e.target.closest('button[type="button"]')) refresh(); }); // add / remove rows (after wire()'s own handlers)
        follow?.addEventListener('change', refresh);
        lineRows().forEach(r => syncLine(r, null)); // old() rows: derive 单件重量 from the stored line total
        refresh();
    })();
</script>
