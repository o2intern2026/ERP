{{-- One goods row of the 无预报收货 form; rendered for old() rows and, with index "__INDEX__", inside the add-row <template>. --}}
@php
    $row = $row ?? [];
    $name = fn (string $field) => "rows[{$index}][{$field}]";
@endphp
<tr class="receipt-row" data-index="{{ $index }}">
    <td><input type="text" name="{{ $name('consignment_mark') }}" value="{{ $row['consignment_mark'] ?? '' }}" maxlength="60" class="scan" style="width:9rem" aria-label="{{ __('warehouse.stock.mark') }}"></td>
    <td class="desc"><input type="text" name="{{ $name('description') }}" value="{{ $row['description'] ?? '' }}" maxlength="255" aria-label="{{ __('warehouse.stock.description') }}"></td>
    <td class="num"><input type="number" name="{{ $name('received_cartons') }}" min="0" value="{{ $row['received_cartons'] ?? '' }}" aria-label="{{ __('warehouse.receiving.received_cartons') }}"></td>
    <td class="num"><input type="number" name="{{ $name('damaged_cartons') }}" min="0" value="{{ $row['damaged_cartons'] ?? 0 }}" aria-label="{{ __('warehouse.receiving.damaged_cartons') }}"></td>
    <td class="type"><select name="{{ $name('unit_type') }}" aria-label="{{ __('warehouse.receiving.unit_type') }}">@foreach ($unitTypes as $t)<option value="{{ $t }}" @selected(($row['unit_type'] ?? 'pallet') === $t)>{{ __('warehouse.unit_types.'.$t) }}</option>@endforeach</select></td>
    <td class="num"><input type="number" name="{{ $name('unit_count') }}" min="1" max="500" value="{{ $row['unit_count'] ?? 1 }}" aria-label="{{ __('warehouse.receiving.unplanned.unit_count') }}"></td>
    <td class="num"><input type="number" name="{{ $name('weight_kg') }}" min="0" step="0.001" value="{{ $row['weight_kg'] ?? '' }}" placeholder="kg" aria-label="{{ __('warehouse.receiving.unplanned.weight_total') }}"></td>
    <td class="desc"><input type="text" name="{{ $name('variance_reason') }}" value="{{ $row['variance_reason'] ?? '' }}" maxlength="255" aria-label="{{ __('warehouse.receiving.unplanned.variance_reason') }}"></td>
    <td><button type="button" class="secondary outline remove-row" aria-label="{{ __('warehouse.receiving.unplanned.remove_row') }}" title="{{ __('warehouse.receiving.unplanned.remove_row') }}">×</button></td>
</tr>
