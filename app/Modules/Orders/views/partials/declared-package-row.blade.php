{{-- One declared package of a pure transport order (A11b; items 5 + 7). Index "__INDEX__" inside the add-package <template>. --}}
@php
    $index = $index ?? '__INDEX__';
    $package = $package ?? [];
    $name = fn (string $field) => "declared_packages[{$index}][{$field}]";
@endphp
<tr class="declared-package" data-index="{{ $index }}">
    <td class="type">@include('orders::partials.package-type-select', ['name' => $name('package_type'), 'value' => $package['package_type'] ?? 'carton', 'ariaLabel' => __('orders.pickup.package_type')])</td>
    <td class="num"><input type="number" min="1" name="{{ $name('qty') }}" value="{{ $package['qty'] ?? '' }}" placeholder="{{ __('orders.pickup.qty') }}" aria-label="{{ __('orders.pickup.qty') }}"></td>
    <td class="num"><input type="number" min="0" step="0.001" name="{{ $name('weight_kg') }}" value="{{ $package['weight_kg'] ?? '' }}" placeholder="kg" aria-label="{{ __('orders.pickup.weight_kg') }}"></td>
    <td class="num"><input type="number" min="0" name="{{ $name('length_mm') }}" value="{{ $package['length_mm'] ?? '' }}" placeholder="mm" aria-label="{{ __('orders.fields.length_mm') }}"></td>
    <td class="num"><input type="number" min="0" name="{{ $name('width_mm') }}" value="{{ $package['width_mm'] ?? '' }}" placeholder="mm" aria-label="{{ __('orders.fields.width_mm') }}"></td>
    <td class="num"><input type="number" min="0" name="{{ $name('height_mm') }}" value="{{ $package['height_mm'] ?? '' }}" placeholder="mm" aria-label="{{ __('orders.fields.height_mm') }}"></td>
    <td><button type="button" class="secondary outline remove-row" aria-label="{{ __('orders.actions.remove_row') }}" title="{{ __('orders.actions.remove_row') }}">×</button></td>
</tr>
