{{-- One typed goods row of 手工建立入库清单 (CHANGE_REQUESTS #128). Rendered for old() / draft rows and, with index "__INDEX__", inside the add-row <template>.
     Every field is named rows[i][field] with the parser's canonical field names; a refused field (rows.i.field in $errors) is marked aria-invalid.
     Lead feedback 2026-09-17 (UI too cramped): one card per goods line, fields grouped 货物 / 重量尺寸 / 收件 with visible labels instead of a 20-column table. --}}
@php
    $index = $index ?? '__INDEX__';
    $row = $row ?? [];
    $name = fn (string $field) => "rows[{$index}][{$field}]";
    $label = fn (string $field) => __('portal.inbound.manual.columns.'.$field);
    $invalid = fn (string $field) => $errors->has('rows.'.$index.'.'.$field) ? 'aria-invalid="true"' : '';
    $value = fn (string $field) => (string) ($row[$field] ?? '');
@endphp
<article class="manual-row" data-index="{{ $index }}" style="margin-bottom:.75rem">
    <header style="display:flex;align-items:center;gap:.75rem;padding:.4rem .9rem">
        <strong class="row-title" data-template="{{ __('portal.inbound.manual.row_title', ['n' => ':n']) }}">{{ __('portal.inbound.manual.row_title', ['n' => is_int($index) ? $index + 1 : '']) }}</strong>
        <span class="row-no" hidden>{{ is_int($index) ? $index + 1 : '' }}</span>
        <small class="text-muted">{{ __('portal.inbound.manual.row_hint') }}</small>
        <span style="margin-left:auto;display:flex;gap:.4rem">
            <button type="button" class="secondary outline copy-prev" title="{{ __('portal.inbound.manual.actions.copy_prev') }}">{{ __('portal.inbound.manual.actions.copy_prev') }}</button>
            <button type="button" class="secondary outline remove-row" aria-label="{{ __('portal.inbound.manual.actions.remove_row') }}" title="{{ __('portal.inbound.manual.actions.remove_row') }}">×</button>
        </span>
    </header>
    <div style="padding:0 .9rem .5rem">
        <small class="text-muted">{{ __('portal.inbound.manual.groups.goods') }}</small>
        <div class="grid">
            <label>{{ $label('consignment_mark') }}<input name="{{ $name('consignment_mark') }}" value="{{ $value('consignment_mark') }}" maxlength="100" placeholder="{{ $label('consignment_mark') }}" aria-label="{{ $label('consignment_mark') }}" {!! $invalid('consignment_mark') !!}></label>
            <label>{{ $label('description_cn') }}<input name="{{ $name('description_cn') }}" value="{{ $value('description_cn') }}" maxlength="255" placeholder="{{ $label('description_cn') }}" aria-label="{{ $label('description_cn') }}" {!! $invalid('description_cn') !!}></label>
            <label>{{ $label('description_en') }}<input name="{{ $name('description_en') }}" value="{{ $value('description_en') }}" maxlength="255" placeholder="{{ $label('description_en') }}" aria-label="{{ $label('description_en') }}" {!! $invalid('description_en') !!}></label>
            <label>{{ $label('package_type') }}<select name="{{ $name('package_type') }}" aria-label="{{ $label('package_type') }}" {!! $invalid('package_type') !!}>
                @foreach ($packageTypes as $type)<option value="{{ $type }}" @selected(($value('package_type') ?: 'carton') === $type)>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($type) }}</option>@endforeach
            </select></label>
            <label>{{ $label('carton_qty') }}<input type="number" min="1" step="1" name="{{ $name('carton_qty') }}" value="{{ $value('carton_qty') }}" class="carton-qty" placeholder="{{ $label('carton_qty') }}" aria-label="{{ $label('carton_qty') }}" {!! $invalid('carton_qty') !!}></label>
            <label>{{ $label('storage_tier') }}<select name="{{ $name('storage_tier') }}" aria-label="{{ $label('storage_tier') }}" {!! $invalid('storage_tier') !!}>
                @foreach ($storageTiers as $tier)<option value="{{ $tier }}" @selected(($value('storage_tier') ?: 'standard') === $tier)>{{ __('portal.inbound.manual.storage_tiers.'.$tier) }}</option>@endforeach
            </select></label>
        </div>
        <small class="text-muted">{{ __('portal.inbound.manual.groups.weight') }}</small>
        <div class="grid">
            {{-- 单件重量 is a helper without a name (never submitted): the JS keeps it and the line total in step through 箱数, like the order form. --}}
            <label>{{ $label('unit_weight') }}<input type="number" min="0" step="0.001" class="unit-weight" value="" placeholder="kg" aria-label="{{ $label('unit_weight') }}"></label>
            <label>{{ $label('actual_weight_kg') }}<input type="number" min="0" step="0.001" name="{{ $name('actual_weight_kg') }}" value="{{ $value('actual_weight_kg') }}" class="line-weight" placeholder="kg" aria-label="{{ $label('actual_weight_kg') }}" {!! $invalid('actual_weight_kg') !!}></label>
            <label>{{ $label('length_mm') }}<input type="number" min="0" step="1" name="{{ $name('length_mm') }}" value="{{ $value('length_mm') }}" placeholder="mm" aria-label="{{ $label('length_mm') }}" {!! $invalid('length_mm') !!}></label>
            <label>{{ $label('width_mm') }}<input type="number" min="0" step="1" name="{{ $name('width_mm') }}" value="{{ $value('width_mm') }}" placeholder="mm" aria-label="{{ $label('width_mm') }}" {!! $invalid('width_mm') !!}></label>
            <label>{{ $label('height_mm') }}<input type="number" min="0" step="1" name="{{ $name('height_mm') }}" value="{{ $value('height_mm') }}" placeholder="mm" aria-label="{{ $label('height_mm') }}" {!! $invalid('height_mm') !!}></label>
        </div>
        <small class="text-muted">{{ __('portal.inbound.manual.groups.consignee') }}</small>
        <div class="grid">
            <label>{{ $label('deliver_to_name') }}<input name="{{ $name('deliver_to_name') }}" value="{{ $value('deliver_to_name') }}" maxlength="255" placeholder="{{ $label('deliver_to_name') }}" aria-label="{{ $label('deliver_to_name') }}" {!! $invalid('deliver_to_name') !!}></label>
            <label>{{ $label('deliver_to_phone') }}<input name="{{ $name('deliver_to_phone') }}" value="{{ $value('deliver_to_phone') }}" maxlength="40" placeholder="{{ $label('deliver_to_phone') }}" aria-label="{{ $label('deliver_to_phone') }}" {!! $invalid('deliver_to_phone') !!}></label>
            <label>{{ $label('deliver_to_address') }}<input name="{{ $name('deliver_to_address') }}" value="{{ $value('deliver_to_address') }}" maxlength="255" placeholder="{{ $label('deliver_to_address') }}" aria-label="{{ $label('deliver_to_address') }}" {!! $invalid('deliver_to_address') !!}></label>
        </div>
        <div class="grid">
            <label>{{ $label('deliver_to_suburb') }}<input name="{{ $name('deliver_to_suburb') }}" value="{{ $value('deliver_to_suburb') }}" maxlength="100" placeholder="{{ $label('deliver_to_suburb') }}" aria-label="{{ $label('deliver_to_suburb') }}" {!! $invalid('deliver_to_suburb') !!}></label>
            <label>{{ $label('deliver_to_state') }}<select name="{{ $name('deliver_to_state') }}" aria-label="{{ $label('deliver_to_state') }}" {!! $invalid('deliver_to_state') !!}>
                <option value="">{{ $label('deliver_to_state') }}</option>
                @foreach ($states as $state)<option value="{{ $state }}" @selected($value('deliver_to_state') === $state)>{{ $state }}</option>@endforeach
            </select></label>
            <label>{{ $label('deliver_to_postcode') }}<input name="{{ $name('deliver_to_postcode') }}" value="{{ $value('deliver_to_postcode') }}" maxlength="4" inputmode="numeric" placeholder="{{ $label('deliver_to_postcode') }}" aria-label="{{ $label('deliver_to_postcode') }}" {!! $invalid('deliver_to_postcode') !!}></label>
            <label>{{ $label('fba_reference') }}<input name="{{ $name('fba_reference') }}" value="{{ $value('fba_reference') }}" maxlength="100" placeholder="{{ $label('fba_reference') }}" aria-label="{{ $label('fba_reference') }}" {!! $invalid('fba_reference') !!}></label>
            <label>{{ $label('requested_date') }}<x-date-field :name="$name('requested_date')" :value="$value('requested_date')" :aria-label="$label('requested_date')" :aria-invalid="$errors->has('rows.'.$index.'.requested_date') ? 'true' : null" /></label>
        </div>
    </div>
</article>
