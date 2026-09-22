{{-- One stock-unit row of the per-line receiving form (audit 2026-09-22 INBOUND-04 / INBOUND-05, CR #141): rendered for old() rows and, with index
     "__INDEX__", inside the add-row <template> — no fixed cap of three units any more. --}}
@php
    $row = $row ?? [];
    $name = fn (string $field) => "units[{$index}][{$field}]";
@endphp
<div class="grid unit-row" data-index="{{ $index }}">
    <select name="{{ $name('unit_type') }}" aria-label="{{ __('warehouse.receiving.unit_type') }}">@foreach (\App\Support\Enums::UNIT_TYPES as $t)<option value="{{ $t }}" @selected(($row['unit_type'] ?? 'pallet') === $t)>{{ __('warehouse.unit_types.'.$t) }}</option>@endforeach</select>
    <input type="number" name="{{ $name('carton_qty') }}" class="unit-qty" min="0" placeholder="{{ __('warehouse.receiving.carton_qty') }}" aria-label="{{ __('warehouse.receiving.carton_qty') }}" value="{{ $row['carton_qty'] ?? '' }}">
    <input type="number" name="{{ $name('length_mm') }}" min="1" placeholder="{{ __('warehouse.receiving.length') }}" aria-label="{{ __('warehouse.receiving.length') }}" value="{{ $row['length_mm'] ?? '' }}">
    <input type="number" name="{{ $name('width_mm') }}" min="1" placeholder="{{ __('warehouse.receiving.width') }}" aria-label="{{ __('warehouse.receiving.width') }}" value="{{ $row['width_mm'] ?? '' }}">
    <input type="number" name="{{ $name('height_mm') }}" min="1" placeholder="{{ __('warehouse.receiving.height') }}" aria-label="{{ __('warehouse.receiving.height') }}" value="{{ $row['height_mm'] ?? '' }}">
    <input type="number" step="0.001" name="{{ $name('weight_kg') }}" min="0" placeholder="{{ __('warehouse.receiving.weight') }}" aria-label="{{ __('warehouse.receiving.weight') }}" value="{{ $row['weight_kg'] ?? '' }}">
    <select name="{{ $name('pallet_source') }}" aria-label="{{ __('warehouse.receiving.pallet_source') }}"><option value="">{{ __('warehouse.receiving.pallet_source') }}</option>@foreach ($palletSources as $s)<option value="{{ $s }}" @selected(($row['pallet_source'] ?? '') === $s)>{{ __('warehouse.pallet_sources.'.$s) }}</option>@endforeach</select>
    <select name="{{ $name('pallet_class') }}" aria-label="{{ __('warehouse.receiving.pallet_class') }}"><option value="">{{ __('warehouse.receiving.pallet_class') }}</option>@foreach ($palletClasses as $c)<option value="{{ $c }}" @selected(($row['pallet_class'] ?? '') === $c)>{{ __('warehouse.pallet_classes.'.$c) }}</option>@endforeach</select>
    <button type="button" class="secondary outline remove-row" aria-label="{{ __('warehouse.receiving.remove_unit') }}" title="{{ __('warehouse.receiving.remove_unit') }}">×</button>
</div>
