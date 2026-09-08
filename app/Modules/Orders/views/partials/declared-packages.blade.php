{{-- Declared packages of a pure transport order (A11b; items 5 + 7): same compact row table as the goods lines; the JS that adds
     rows lives in orders::partials.goods-lines (always on the same form). --}}
@php
    $rows = array_values(array_filter((array) old('declared_packages', []), 'is_array')) ?: [['package_type' => 'carton']];
@endphp
<div class="overflow-auto">
    <table class="form-rows" id="declared-packages">
        <thead><tr>
            <th>{{ __('orders.pickup.package_type') }}</th>
            <th>{{ __('orders.pickup.qty') }}</th>
            <th>{{ __('orders.pickup.weight_kg') }}</th>
            <th>{{ __('orders.fields.length_mm') }}</th>
            <th>{{ __('orders.fields.width_mm') }}</th>
            <th>{{ __('orders.fields.height_mm') }}</th>
            <th></th>
        </tr></thead>
        <tbody>
            @foreach ($rows as $index => $package)
                @include('orders::partials.declared-package-row', ['index' => $index, 'package' => $package])
            @endforeach
        </tbody>
    </table>
</div>
<button type="button" class="secondary outline form-rows-add" id="add-declared-package">{{ __('orders.actions.add_package') }}</button>
<template id="declared-package-template">@include('orders::partials.declared-package-row', ['index' => '__INDEX__', 'package' => []])</template>
