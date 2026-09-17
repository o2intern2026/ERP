{{-- 手工建立入库清单 (CHANGE_REQUESTS #128): the typed goods rows — the CSV template's fields, one row per goods line, add / remove by vanilla JS.
     Rows are numbered by position (the same number the server's row messages use); the 单件重量 helper mirrors the order form. --}}
@php($columns = ['consignment_mark', 'description_cn', 'description_en', 'package_type', 'carton_qty', 'unit_weight', 'actual_weight_kg', 'length_mm', 'width_mm', 'height_mm', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference', 'storage_tier', 'requested_date'])
<p class="text-muted"><small>{{ __('portal.inbound.manual.rows_hint') }}</small></p>
<div class="overflow-auto">
    <table class="form-rows dense" id="manual-rows">
        <thead><tr>
            <th>{{ __('portal.inbound.manual.columns.row') }}</th>
            @foreach ($columns as $column)<th>{{ __('portal.inbound.manual.columns.'.$column) }}</th>@endforeach
            <th></th>
        </tr></thead>
        <tbody>
            @foreach ($rows as $index => $row)
                @include('portal::asns.imports.partials.manual-row', ['index' => $index, 'row' => $row])
            @endforeach
        </tbody>
    </table>
</div>
<button type="button" class="secondary outline form-rows-add" id="add-manual-row">{{ __('portal.inbound.manual.actions.add_row') }}</button>
<template id="manual-row-template">@include('portal::asns.imports.partials.manual-row', ['index' => '__INDEX__', 'row' => []])</template>
<script>
    (function () {
        var body = document.querySelector('#manual-rows tbody');
        var template = document.getElementById('manual-row-template');
        function rows() { return Array.prototype.slice.call(body.querySelectorAll('tr.manual-row')); }
        function renumber() { rows().forEach(function (tr, i) { tr.querySelector('.row-no').textContent = String(i + 1); }); }
        function next() { return rows().reduce(function (max, tr) { return Math.max(max, Number(tr.dataset.index) || 0); }, -1) + 1; }
        document.getElementById('add-manual-row').addEventListener('click', function () {
            body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next())));
            renumber();
            var first = body.lastElementChild.querySelector('input, select');
            if (first) { first.focus(); }
        });
        body.addEventListener('click', function (event) {
            var button = event.target.closest('button.remove-row');
            if (!button) { return; }
            var row = button.closest('tr');
            if (rows().length > 1) { row.remove(); } else { row.querySelectorAll('input').forEach(function (input) { input.value = ''; }); }
            renumber();
        });
        // 单件重量 ⇄ 整行合计 through 箱数, whichever the person types (the helper is never submitted).
        function round3(n) { return Math.round(n * 1000) / 1000; }
        body.addEventListener('input', function (event) {
            var input = event.target, tr = input.closest('tr');
            if (!tr) { return; }
            var qty = Number(tr.querySelector('.carton-qty').value) || 0, unit = tr.querySelector('.unit-weight'), line = tr.querySelector('.line-weight');
            if (input === unit && qty > 0 && unit.value !== '') { line.value = round3(Number(unit.value) * qty); }
            else if ((input === line || input.classList.contains('carton-qty')) && qty > 0 && line.value !== '') { unit.value = round3(Number(line.value) / qty); }
        });
        rows().forEach(function (tr) {
            var qty = Number(tr.querySelector('.carton-qty').value) || 0, line = tr.querySelector('.line-weight');
            if (qty > 0 && line.value !== '') { tr.querySelector('.unit-weight').value = round3(Number(line.value) / qty); }
        });
        renumber();
    })();
</script>
