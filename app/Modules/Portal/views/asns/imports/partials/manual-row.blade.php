{{-- One typed goods row of 手工建立入库清单 (CHANGE_REQUESTS #128). Rendered for old() / draft rows and, with index "__INDEX__", inside the add-row <template>.
     Every cell is named rows[i][field] with the parser's canonical field names; a refused cell (rows.i.field in $errors) is marked aria-invalid. --}}
@php
    $index = $index ?? '__INDEX__';
    $row = $row ?? [];
    $name = fn (string $field) => "rows[{$index}][{$field}]";
    $label = fn (string $field) => __('portal.inbound.manual.columns.'.$field);
    $invalid = fn (string $field) => $errors->has('rows.'.$index.'.'.$field) ? 'aria-invalid="true"' : '';
    $value = fn (string $field) => (string) ($row[$field] ?? '');
@endphp
<tr class="manual-row" data-index="{{ $index }}">
    <td class="row-no">{{ is_int($index) ? $index + 1 : '' }}</td>
    <td><input name="{{ $name('consignment_mark') }}" value="{{ $value('consignment_mark') }}" maxlength="100" placeholder="{{ $label('consignment_mark') }}" aria-label="{{ $label('consignment_mark') }}" {!! $invalid('consignment_mark') !!}></td>
    <td><input name="{{ $name('description_cn') }}" value="{{ $value('description_cn') }}" maxlength="255" placeholder="{{ $label('description_cn') }}" aria-label="{{ $label('description_cn') }}" {!! $invalid('description_cn') !!}></td>
    <td><input name="{{ $name('description_en') }}" value="{{ $value('description_en') }}" maxlength="255" placeholder="{{ $label('description_en') }}" aria-label="{{ $label('description_en') }}" {!! $invalid('description_en') !!}></td>
    <td><select name="{{ $name('package_type') }}" aria-label="{{ $label('package_type') }}" {!! $invalid('package_type') !!}>
        @foreach ($packageTypes as $type)<option value="{{ $type }}" @selected(($value('package_type') ?: 'carton') === $type)>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($type) }}</option>@endforeach
    </select></td>
    <td class="num"><input type="number" min="1" step="1" name="{{ $name('carton_qty') }}" value="{{ $value('carton_qty') }}" class="carton-qty" placeholder="{{ $label('carton_qty') }}" aria-label="{{ $label('carton_qty') }}" {!! $invalid('carton_qty') !!}></td>
    {{-- 单件重量 is a helper without a name (never submitted): the JS keeps it and the line total in step through 箱数, like the order form. --}}
    <td class="num"><input type="number" min="0" step="0.001" class="unit-weight" value="" placeholder="kg" aria-label="{{ $label('unit_weight') }}"></td>
    <td class="num"><input type="number" min="0" step="0.001" name="{{ $name('actual_weight_kg') }}" value="{{ $value('actual_weight_kg') }}" class="line-weight" placeholder="kg" aria-label="{{ $label('actual_weight_kg') }}" {!! $invalid('actual_weight_kg') !!}></td>
    <td class="num"><input type="number" min="0" step="1" name="{{ $name('length_mm') }}" value="{{ $value('length_mm') }}" placeholder="mm" aria-label="{{ $label('length_mm') }}" {!! $invalid('length_mm') !!}></td>
    <td class="num"><input type="number" min="0" step="1" name="{{ $name('width_mm') }}" value="{{ $value('width_mm') }}" placeholder="mm" aria-label="{{ $label('width_mm') }}" {!! $invalid('width_mm') !!}></td>
    <td class="num"><input type="number" min="0" step="1" name="{{ $name('height_mm') }}" value="{{ $value('height_mm') }}" placeholder="mm" aria-label="{{ $label('height_mm') }}" {!! $invalid('height_mm') !!}></td>
    <td><input name="{{ $name('deliver_to_name') }}" value="{{ $value('deliver_to_name') }}" maxlength="255" placeholder="{{ $label('deliver_to_name') }}" aria-label="{{ $label('deliver_to_name') }}" {!! $invalid('deliver_to_name') !!}></td>
    <td><input name="{{ $name('deliver_to_phone') }}" value="{{ $value('deliver_to_phone') }}" maxlength="40" placeholder="{{ $label('deliver_to_phone') }}" aria-label="{{ $label('deliver_to_phone') }}" {!! $invalid('deliver_to_phone') !!}></td>
    <td><input name="{{ $name('deliver_to_address') }}" value="{{ $value('deliver_to_address') }}" maxlength="255" placeholder="{{ $label('deliver_to_address') }}" aria-label="{{ $label('deliver_to_address') }}" {!! $invalid('deliver_to_address') !!}></td>
    <td><input name="{{ $name('deliver_to_suburb') }}" value="{{ $value('deliver_to_suburb') }}" maxlength="100" placeholder="{{ $label('deliver_to_suburb') }}" aria-label="{{ $label('deliver_to_suburb') }}" {!! $invalid('deliver_to_suburb') !!}></td>
    <td><select name="{{ $name('deliver_to_state') }}" aria-label="{{ $label('deliver_to_state') }}" {!! $invalid('deliver_to_state') !!}>
        <option value="">{{ $label('deliver_to_state') }}</option>
        @foreach ($states as $state)<option value="{{ $state }}" @selected($value('deliver_to_state') === $state)>{{ $state }}</option>@endforeach
    </select></td>
    <td><input name="{{ $name('deliver_to_postcode') }}" value="{{ $value('deliver_to_postcode') }}" maxlength="4" inputmode="numeric" placeholder="{{ $label('deliver_to_postcode') }}" aria-label="{{ $label('deliver_to_postcode') }}" {!! $invalid('deliver_to_postcode') !!}></td>
    <td><input name="{{ $name('fba_reference') }}" value="{{ $value('fba_reference') }}" maxlength="100" placeholder="{{ $label('fba_reference') }}" aria-label="{{ $label('fba_reference') }}" {!! $invalid('fba_reference') !!}></td>
    <td><select name="{{ $name('storage_tier') }}" aria-label="{{ $label('storage_tier') }}" {!! $invalid('storage_tier') !!}>
        @foreach ($storageTiers as $tier)<option value="{{ $tier }}" @selected(($value('storage_tier') ?: 'standard') === $tier)>{{ __('portal.inbound.manual.storage_tiers.'.$tier) }}</option>@endforeach
    </select></td>
    <td><input type="date" name="{{ $name('requested_date') }}" value="{{ $value('requested_date') }}" aria-label="{{ $label('requested_date') }}" {!! $invalid('requested_date') !!}></td>
    <td><button type="button" class="secondary outline remove-row" aria-label="{{ __('portal.inbound.manual.actions.remove_row') }}" title="{{ __('portal.inbound.manual.actions.remove_row') }}">×</button></td>
</tr>
