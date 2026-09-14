{{-- 到仓方式 我方上门提货 (CHANGE_REQUESTS #124): pickup address, ready date, notes and the declared package rows. Shared by the create
     form (blank) and the ASN page's 修改 form (prefilled from $asn). $idPrefix keeps element ids unique when both forms sit on one page. --}}
@php
    $address = old('collection', $asn?->collection_address ?? []);
    $packages = old('collection_packages', $asn?->collection_packages ?? []);
    if ($packages === []) { $packages = [['package_type' => 'carton', 'qty' => 1, 'weight_kg' => '', 'length_mm' => '', 'width_mm' => '', 'height_mm' => '']]; }
    $idPrefix ??= 'collection';
@endphp
<div class="grid">
    <label>{{ __('warehouse.asns.collection.fields.name') }}<input type="text" name="collection[name]" value="{{ $address['name'] ?? '' }}" maxlength="255"></label>
    <label>{{ __('warehouse.asns.collection.fields.phone') }}<input type="text" name="collection[phone]" value="{{ $address['phone'] ?? '' }}" maxlength="40"></label>
    <label>{{ __('warehouse.asns.collection.fields.type') }}<select name="collection[type]">@foreach (\App\Support\Enums::ADDRESS_TYPES as $t)<option value="{{ $t }}" @selected(($address['type'] ?? 'business') === $t)>{{ __('warehouse.asns.collection.address_types.'.$t) }}</option>@endforeach</select></label>
</div>
<div class="grid">
    <label>{{ __('warehouse.asns.collection.fields.address') }}<input type="text" name="collection[address]" value="{{ $address['address'] ?? '' }}" maxlength="255"></label>
    <label>{{ __('warehouse.asns.collection.fields.suburb') }}<input type="text" name="collection[suburb]" value="{{ $address['suburb'] ?? '' }}" maxlength="100"></label>
    <label>{{ __('warehouse.asns.collection.fields.state') }}<select name="collection[state]"><option value="">—</option>@foreach (\App\Support\Enums::STATES as $state)<option value="{{ $state }}" @selected(($address['state'] ?? '') === $state)>{{ $state }}</option>@endforeach</select></label>
    <label>{{ __('warehouse.asns.collection.fields.postcode') }}<input type="text" name="collection[postcode]" value="{{ $address['postcode'] ?? '' }}" maxlength="10"></label>
</div>
<div class="grid">
    <label>{{ __('warehouse.asns.collection.fields.ready_date') }}<input type="date" name="collection_ready_date" value="{{ old('collection_ready_date', $asn?->collection_ready_date?->format('Y-m-d')) }}" min="{{ today()->toDateString() }}"></label>
    <label>{{ __('warehouse.asns.collection.fields.notes') }}<input type="text" name="collection_notes" value="{{ old('collection_notes', $asn?->collection_notes) }}" maxlength="2000" placeholder="{{ __('warehouse.asns.collection.notes_placeholder') }}"></label>
</div>
<p class="text-muted" style="margin:.2rem 0"><small>{{ __('warehouse.asns.collection.packages_hint') }}</small></p>
<div class="overflow-auto"><table class="dense form-rows" id="{{ $idPrefix }}-packages">
    <thead><tr><th>{{ __('warehouse.asns.collection.package_fields.package_type') }}</th><th class="num">{{ __('warehouse.asns.collection.package_fields.qty') }}</th><th class="num">{{ __('warehouse.asns.collection.package_fields.weight_kg') }}</th><th class="num">{{ __('warehouse.asns.collection.package_fields.length_mm') }}</th><th class="num">{{ __('warehouse.asns.collection.package_fields.width_mm') }}</th><th class="num">{{ __('warehouse.asns.collection.package_fields.height_mm') }}</th><th></th></tr></thead>
    <tbody>
    @foreach ($packages as $i => $pkg)
        <tr>
            <td><select name="collection_packages[{{ $i }}][package_type]">@foreach (\App\Support\Enums::COLLECTION_PACKAGE_TYPES as $t)<option value="{{ $t }}" @selected(($pkg['package_type'] ?? 'carton') === $t)>{{ __('warehouse.asns.collection.package_types.'.$t) }}</option>@endforeach</select></td>
            <td class="num"><input type="number" name="collection_packages[{{ $i }}][qty]" min="1" step="1" value="{{ $pkg['qty'] ?? '' }}"></td>
            <td class="num"><input type="number" name="collection_packages[{{ $i }}][weight_kg]" min="0" step="0.001" value="{{ $pkg['weight_kg'] ?? '' }}"></td>
            <td class="num"><input type="number" name="collection_packages[{{ $i }}][length_mm]" min="0" step="1" value="{{ $pkg['length_mm'] ?? '' }}"></td>
            <td class="num"><input type="number" name="collection_packages[{{ $i }}][width_mm]" min="0" step="1" value="{{ $pkg['width_mm'] ?? '' }}"></td>
            <td class="num"><input type="number" name="collection_packages[{{ $i }}][height_mm]" min="0" step="1" value="{{ $pkg['height_mm'] ?? '' }}"></td>
            <td><button type="button" class="secondary outline" data-remove-row style="padding:.2rem .6rem;width:auto">{{ __('warehouse.asns.collection.remove_package') }}</button></td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<button type="button" class="secondary outline" data-add-row="{{ $idPrefix }}-packages" style="width:auto">{{ __('warehouse.asns.collection.add_package') }}</button>
<script>
    (function () {
        var table = document.getElementById('{{ $idPrefix }}-packages');
        var addButton = document.querySelector('[data-add-row="{{ $idPrefix }}-packages"]');
        var body = table.querySelector('tbody');
        var renumber = function () {
            Array.prototype.forEach.call(body.querySelectorAll('tr'), function (row, index) {
                Array.prototype.forEach.call(row.querySelectorAll('select, input'), function (field) {
                    field.name = field.name.replace(/collection_packages\[\d+\]/, 'collection_packages[' + index + ']');
                });
            });
        };
        addButton.addEventListener('click', function () {
            var last = body.querySelector('tr:last-child');
            var row = last.cloneNode(true);
            Array.prototype.forEach.call(row.querySelectorAll('input'), function (field) { field.value = field.name.indexOf('[qty]') > -1 ? '1' : ''; });
            body.appendChild(row);
            renumber();
        });
        body.addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-row]');
            if (!button) return;
            if (body.querySelectorAll('tr').length > 1) { button.closest('tr').remove(); } else { Array.prototype.forEach.call(button.closest('tr').querySelectorAll('input'), function (f) { f.value = ''; }); }
            renumber();
        });
    })();
</script>
