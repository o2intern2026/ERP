{{-- One row of the pack form. $i = the packages[] index (a number, or __INDEX__ inside the <template>); $n = the visible row number; $row = the
     CHANGE_REQUESTS #162 prefill for this row (package_type, qty, weight_kg, dims, label_code, source, complete) or [] for a blank row.
     Audit 2026-09-22 OUTBOUND-01: 件数 (qty, default 1) — the server expands the row into that many Package rows (PKG-n-01…), so identical
     cartons are one row and the label count / carrier item list are right. --}}
<tr data-index="{{ $i }}">
    <td class="row-no">{{ $n }}</td>
    <td><select name="packages[{{ $i }}][package_type]" aria-label="{{ __('warehouse.outbound.package_type') }}"><option value="">—</option>@foreach ($packageTypes as $t)<option value="{{ $t }}" @selected(old("packages.$i.package_type", $default ?? '') === $t)>{{ __('warehouse.package_types.'.$t) }}</option>@endforeach</select></td>
    <td><input type="number" min="1" max="200" step="1" name="packages[{{ $i }}][qty]" value="{{ old("packages.$i.qty", $row['qty'] ?? 1) }}" style="width:5rem" aria-label="{{ __('warehouse.outbound.qty') }}"></td>
    <td><input type="number" step="0.001" min="0.001" name="packages[{{ $i }}][weight_kg]" value="{{ old("packages.$i.weight_kg", $row['weight_kg'] ?? '') }}" aria-label="{{ __('warehouse.outbound.weight') }}"></td>
    <td><input type="number" min="1" name="packages[{{ $i }}][length_mm]" value="{{ old("packages.$i.length_mm", $row['length_mm'] ?? '') }}" aria-label="{{ __('warehouse.outbound.length') }}"></td>
    <td><input type="number" min="1" name="packages[{{ $i }}][width_mm]" value="{{ old("packages.$i.width_mm", $row['width_mm'] ?? '') }}" aria-label="{{ __('warehouse.outbound.width') }}"></td>
    <td><input type="number" min="1" name="packages[{{ $i }}][height_mm]" value="{{ old("packages.$i.height_mm", $row['height_mm'] ?? '') }}" aria-label="{{ __('warehouse.outbound.height') }}"></td>
    {{-- CHANGE_REQUESTS #162: which picked unit filled the row and where its numbers came from; a unit without data is flagged for hand entry. --}}
    <td>@if (($row['label_code'] ?? null) !== null)<small><code>{{ $row['label_code'] }}</code> @if ($row['complete'] ?? false)<span class="badge" data-tone="ok">{{ __('warehouse.outbound.prefill_sources.'.$row['source']) }}</span>@else<mark>{{ __('warehouse.outbound.prefill_missing_row') }}</mark>@endif</small>@endif</td>
</tr>
