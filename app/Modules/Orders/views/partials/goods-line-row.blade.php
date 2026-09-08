{{-- One goods line (items 5 + 7). Rendered for saved / old() rows and, with index "__INDEX__", inside the add-line <template>. --}}
@php
    $index = $index ?? '__INDEX__';
    $line = $line ?? [];
    $first = $first ?? false;
    $extended = $extended ?? false;
    $prefix = $prefix ?? 'orders';
    $name = fn (string $field) => "lines[{$index}][{$field}]";
@endphp
<tr class="goods-line" data-index="{{ $index }}">
    <td class="desc"><input name="{{ $name('description_cn') }}" value="{{ $line['description_cn'] ?? '' }}" placeholder="{{ __($prefix.'.fields.description_cn') }}" aria-label="{{ __($prefix.'.fields.description_cn') }}"></td>
    <td class="desc"><input name="{{ $name('description_en') }}" value="{{ $line['description_en'] ?? '' }}" placeholder="{{ __($prefix.'.fields.description_en') }}" aria-label="{{ __($prefix.'.fields.description_en') }}"></td>
    <td class="type">@include('orders::partials.package-type-select', ['name' => $name('package_type'), 'value' => $line['package_type'] ?? 'carton', 'class' => $first ? 'goods-required' : '', 'required' => $first, 'ariaLabel' => __($prefix.'.fields.package_type')])</td>
    <td class="num"><input type="number" min="1" name="{{ $name('carton_qty') }}" value="{{ $line['carton_qty'] ?? '' }}" class="{{ $first ? 'goods-required' : '' }}" @required($first) placeholder="{{ __($prefix.'.fields.carton_qty') }}" aria-label="{{ __($prefix.'.fields.carton_qty') }}"></td>
    @if ($extended)
        <td class="num"><input type="number" min="0" name="{{ $name('unit_qty') }}" value="{{ $line['unit_qty'] ?? '' }}" placeholder="{{ __('orders.fields.unit_qty') }}" aria-label="{{ __('orders.fields.unit_qty') }}"></td>
    @endif
    <td class="num"><input type="number" min="0" step="0.001" name="{{ $name('actual_weight_kg') }}" value="{{ $line['actual_weight_kg'] ?? '' }}" placeholder="kg" aria-label="{{ __('orders.lines.weight_total') }}"></td>
    <td class="num"><input type="number" min="0" name="{{ $name('length_mm') }}" value="{{ $line['length_mm'] ?? '' }}" placeholder="mm" aria-label="{{ __($prefix.'.fields.length_mm') }}"></td>
    <td class="num"><input type="number" min="0" name="{{ $name('width_mm') }}" value="{{ $line['width_mm'] ?? '' }}" placeholder="mm" aria-label="{{ __($prefix.'.fields.width_mm') }}"></td>
    <td class="num"><input type="number" min="0" name="{{ $name('height_mm') }}" value="{{ $line['height_mm'] ?? '' }}" placeholder="mm" aria-label="{{ __($prefix.'.fields.height_mm') }}"></td>
    @if ($extended)
        <td class="num"><input type="number" min="0" step="0.0001" name="{{ $name('cbm') }}" value="{{ $line['cbm'] ?? '' }}" placeholder="CBM" aria-label="{{ __('orders.fields.cbm') }}"></td>
    @endif
    <td><button type="button" class="secondary outline remove-row" aria-label="{{ __('orders.actions.remove_row') }}" title="{{ __('orders.actions.remove_row') }}">×</button></td>
</tr>
