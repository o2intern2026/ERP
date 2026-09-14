{{-- Declared packages of a pure transport order (A11b; items 5 + 7): same compact row table as the goods lines; the JS that adds
     rows lives in orders::partials.goods-lines (always on the same form). --}}
@php
    $rows = array_values(array_filter((array) old('declared_packages', []), 'is_array')) ?: [['package_type' => 'carton']];
@endphp
{{-- 2026-09-14 lead feedback: by default the packages mirror the goods lines (JS in orders::partials.goods-lines); untick to type them by hand.
     packages_follow_lines is not validated — it only carries the choice through old() after a failed submit. --}}
<label>
    <input type="hidden" name="packages_follow_lines" value="0">
    <input type="checkbox" id="packages-follow-lines" name="packages_follow_lines" value="1" @checked(old('packages_follow_lines', '1') === '1')>
    {{ __('orders.pickup.follow_lines') }}
</label>
<p class="text-muted"><small>{{ __('orders.pickup.follow_hint') }}</small></p>
<div class="overflow-auto">
    <table class="form-rows" id="declared-packages">
        <thead><tr>
            <th>{{ __('orders.pickup.package_type') }}</th>
            <th>{{ __('orders.pickup.qty') }}</th>
            <th>{{ __('orders.pickup.weight_kg') }} <small class="text-muted" title="{{ __('orders.pickup.weight_required') }}">*</small></th>
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
        <tfoot><tr><td colspan="7"><small class="text-muted" id="declared-packages-total" data-template="{{ __('orders.pickup.totals', ['qty' => ':qty', 'kg' => ':kg']) }}">{{ __('orders.pickup.totals', ['qty' => 0, 'kg' => 0]) }}</small></td></tr></tfoot>
    </table>
</div>
<button type="button" class="secondary outline form-rows-add" id="add-declared-package">{{ __('orders.actions.add_package') }}</button>
<template id="declared-package-template">@include('orders::partials.declared-package-row', ['index' => '__INDEX__', 'package' => []])</template>
