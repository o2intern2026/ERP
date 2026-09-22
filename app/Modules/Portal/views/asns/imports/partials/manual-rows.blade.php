{{-- 手工建立入库清单 (CHANGE_REQUESTS #128): the typed goods rows — the CSV template's fields, one CARD per goods line (lead feedback 2026-09-17:
     the 20-column table was too cramped), add / remove / 收件信息同上一行 by vanilla JS. Rows are numbered by position (the same number the
     server's row messages use); the 单件重量 helper mirrors the order form. --}}
<p class="text-muted"><small>{{ __('portal.inbound.manual.rows_hint') }}</small></p>
<div id="manual-rows">
    @foreach ($rows as $index => $row)
        @include('portal::asns.imports.partials.manual-row', ['index' => $index, 'row' => $row])
    @endforeach
</div>
<button type="button" class="secondary outline form-rows-add" id="add-manual-row">{{ __('portal.inbound.manual.actions.add_row') }}</button>
<template id="manual-row-template">@include('portal::asns.imports.partials.manual-row', ['index' => '__INDEX__', 'row' => []])</template>
<script>
    (function () {
        var body = document.getElementById('manual-rows');
        var template = document.getElementById('manual-row-template');
        var consignee = ['deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'deliver_to_address_type', 'fba_reference', 'requested_date'];
        function rows() { return Array.prototype.slice.call(body.querySelectorAll('.manual-row')); }
        function field(card, name) { return card.querySelector('[name$="[' + name + ']"]'); }
        function renumber() {
            rows().forEach(function (card, i) {
                var title = card.querySelector('.row-title');
                title.textContent = title.dataset.template.replace(':n', String(i + 1));
                card.querySelector('.row-no').textContent = String(i + 1);
                card.querySelector('.copy-prev').hidden = i === 0; // the first card has nothing above it
            });
        }
        function next() { return rows().reduce(function (max, card) { return Math.max(max, Number(card.dataset.index) || 0); }, -1) + 1; }
        document.getElementById('add-manual-row').addEventListener('click', function () {
            body.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(next())));
            renumber();
            var first = body.lastElementChild.querySelector('input, select');
            if (first) { first.focus(); }
        });
        body.addEventListener('click', function (event) {
            var remove = event.target.closest('button.remove-row'), copy = event.target.closest('button.copy-prev');
            if (remove) {
                var card = remove.closest('.manual-row');
                if (rows().length > 1) { card.remove(); } else { card.querySelectorAll('input').forEach(function (input) { input.value = ''; }); }
                renumber();
            } else if (copy) {
                // 收件信息同上一行: the consignee block is copied from the card above (the same mark spread over several lines).
                var here = copy.closest('.manual-row'), all = rows(), prev = all[all.indexOf(here) - 1];
                if (!prev) { return; }
                consignee.forEach(function (name) { var from = field(prev, name), to = field(here, name); if (from && to) { to.value = from.value; to.dispatchEvent(new Event('change', { bubbles: true })); } }); // requested_date is an x-date-field: change refreshes its dd/mm/yyyy text
                var mark = field(prev, 'consignment_mark'), mine = field(here, 'consignment_mark');
                if (mark && mine && mine.value === '') { mine.value = mark.value; }
            }
        });
        // 单件重量 ⇄ 整行合计 through 箱数, whichever the person types (the helper is never submitted).
        function round3(n) { return Math.round(n * 1000) / 1000; }
        body.addEventListener('input', function (event) {
            var input = event.target, card = input.closest('.manual-row');
            if (!card) { return; }
            var qty = Number(card.querySelector('.carton-qty').value) || 0, unit = card.querySelector('.unit-weight'), line = card.querySelector('.line-weight');
            if (input === unit && qty > 0 && unit.value !== '') { line.value = round3(Number(unit.value) * qty); }
            else if ((input === line || input.classList.contains('carton-qty')) && qty > 0 && line.value !== '') { unit.value = round3(Number(line.value) / qty); }
        });
        rows().forEach(function (card) {
            var qty = Number(card.querySelector('.carton-qty').value) || 0, line = card.querySelector('.line-weight');
            if (qty > 0 && line.value !== '') { card.querySelector('.unit-weight').value = round3(Number(line.value) / qty); }
        });
        renumber();
    })();
</script>
