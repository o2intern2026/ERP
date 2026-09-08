{{-- Items 5 + 7 (tester feedback): compact goods-line table shared by orders::form and portal::orders.create — one line per row with
     包装类型 / 箱数 / 重量 / 长 / 宽 / 高 (staff also 件数 / CBM); rows are added from the <template> by vanilla JS and spare rows are
     pruned server side (OrderFormRows). Labels come from the caller's lang file ($prefix); the CSS is inline (CHANGE_REQUESTS #80). --}}
@php
    $prefix = $prefix ?? 'orders';
    $extended = $extended ?? false;
    $rows = array_values(array_filter((array) old('lines', []), 'is_array')) ?: [['package_type' => 'carton', 'carton_qty' => 1]];
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
    })();
</script>
