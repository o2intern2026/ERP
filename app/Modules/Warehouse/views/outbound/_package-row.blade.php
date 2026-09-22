{{-- One row of the pack form. $i = the packages[] index (a number, or __INDEX__ inside the <template>); $n = the visible row number.
     Audit 2026-09-22 OUTBOUND-01: 件数 (qty, default 1) — the server expands the row into that many Package rows (PKG-n-01…), so identical
     cartons are one row and the label count / carrier item list are right. --}}
<tr data-index="{{ $i }}">
    <td class="row-no">{{ $n }}</td>
    <td><select name="packages[{{ $i }}][package_type]" aria-label="{{ __('warehouse.outbound.package_type') }}"><option value="">—</option>@foreach ($packageTypes as $t)<option value="{{ $t }}" @selected(old("packages.$i.package_type", $default ?? '') === $t)>{{ __('warehouse.package_types.'.$t) }}</option>@endforeach</select></td>
    <td><input type="number" min="1" max="200" step="1" name="packages[{{ $i }}][qty]" value="{{ old("packages.$i.qty", 1) }}" style="width:5rem" aria-label="{{ __('warehouse.outbound.qty') }}"></td>
    <td><input type="number" step="0.001" min="0.001" name="packages[{{ $i }}][weight_kg]" value="{{ old("packages.$i.weight_kg") }}" aria-label="{{ __('warehouse.outbound.weight') }}"></td>
    <td><input type="number" min="1" name="packages[{{ $i }}][length_mm]" value="{{ old("packages.$i.length_mm") }}" aria-label="{{ __('warehouse.outbound.length') }}"></td>
    <td><input type="number" min="1" name="packages[{{ $i }}][width_mm]" value="{{ old("packages.$i.width_mm") }}" aria-label="{{ __('warehouse.outbound.width') }}"></td>
    <td><input type="number" min="1" name="packages[{{ $i }}][height_mm]" value="{{ old("packages.$i.height_mm") }}" aria-label="{{ __('warehouse.outbound.height') }}"></td>
</tr>
