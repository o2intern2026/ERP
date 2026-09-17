{{-- CHANGE_REQUESTS #125: 到仓方式. The pickup fieldset is hidden AND disabled unless 需要你们上门提货 is ticked, so nothing of it submits otherwise.
     CHANGE_REQUESTS #128: shared by the upload page and 手工建立入库清单; $defaults = what a draft filled in (old() always wins). --}}
@php($defaults = $defaults ?? [])
@php($weCollect = old('inbound_transport', $defaults['inbound_transport'] ?? null) === 'we_collect')
@php($pickup = is_array($defaults['collection'] ?? null) ? $defaults['collection'] : [])
<article class="kv-card" id="inbound-transport">
    <strong>{{ __('portal.inbound.collection.section') }}</strong>
    <p style="margin:.3rem 0">
        @foreach (\App\Support\Enums::ASN_INBOUND_TRANSPORTS as $mode)
            <label style="display:inline-block;margin-right:1.2rem"><input type="radio" name="inbound_transport" value="{{ $mode }}" @checked(($weCollect ? 'we_collect' : 'client_delivers') === $mode)> {{ __('portal.inbound.collection.modes.'.$mode) }}</label>
        @endforeach
    </p>
    <fieldset id="collection-fields" {{ $weCollect ? '' : 'hidden disabled' }}>
        <p class="text-muted" style="margin:.2rem 0"><small>{{ __('portal.inbound.collection.hint') }}</small></p>
        @if ($warehouses->count() === 1)
            <input type="hidden" name="warehouse_id" value="{{ $warehouses->first()->id }}">
        @else
            <label>{{ __('portal.inbound.collection.fields.warehouse') }}
                <select name="warehouse_id">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $defaults['warehouse_id'] ?? $defaultWarehouseId) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
            </label>
        @endif
        <div class="grid">
            <label>{{ __('portal.inbound.collection.fields.name') }}<input type="text" name="collection[name]" maxlength="255" value="{{ old('collection.name', $pickup['name'] ?? '') }}"></label>
            <label>{{ __('portal.inbound.collection.fields.phone') }}<input type="text" name="collection[phone]" maxlength="40" value="{{ old('collection.phone', $pickup['phone'] ?? '') }}"></label>
            <label>{{ __('portal.inbound.collection.fields.type') }}<select name="collection[type]">@foreach (\App\Support\Enums::ADDRESS_TYPES as $t)<option value="{{ $t }}" @selected(old('collection.type', ($pickup['type'] ?? null) ?: 'business') === $t)>{{ __('portal.inbound.collection.address_types.'.$t) }}</option>@endforeach</select></label>
        </div>
        <div class="grid">
            <label>{{ __('portal.inbound.collection.fields.address') }}<input type="text" name="collection[address]" maxlength="255" value="{{ old('collection.address', $pickup['address'] ?? '') }}"></label>
            <label>{{ __('portal.inbound.collection.fields.suburb') }}<input type="text" name="collection[suburb]" maxlength="100" value="{{ old('collection.suburb', $pickup['suburb'] ?? '') }}"></label>
            <label>{{ __('portal.inbound.collection.fields.state') }}<select name="collection[state]"><option value="">{{ __('portal.actions.select') }}</option>@foreach (\App\Support\Enums::STATES as $state)<option value="{{ $state }}" @selected(old('collection.state', $pickup['state'] ?? '') === $state)>{{ $state }}</option>@endforeach</select></label>
            <label>{{ __('portal.inbound.collection.fields.postcode') }}<input type="text" name="collection[postcode]" maxlength="4" inputmode="numeric" value="{{ old('collection.postcode', $pickup['postcode'] ?? '') }}"></label>
        </div>
        <div class="grid">
            <label>{{ __('portal.inbound.collection.fields.ready_date') }}<x-date-field name="collection_ready_date" min="{{ today()->toDateString() }}" value="{{ old('collection_ready_date', $defaults['collection_ready_date'] ?? '') }}" /></label>
            <label>{{ __('portal.inbound.collection.fields.notes') }}<input type="text" name="collection_notes" maxlength="2000" value="{{ old('collection_notes', $defaults['collection_notes'] ?? '') }}"></label>
        </div>
    </fieldset>
</article>
<script>
    (function () {
        var modes = Array.prototype.slice.call(document.querySelectorAll('input[name="inbound_transport"]'));
        var box = document.getElementById('collection-fields');
        function toggle() {
            var weCollect = modes.some(function (r) { return r.checked && r.value === 'we_collect'; });
            box.hidden = !weCollect;
            box.disabled = !weCollect;
        }
        modes.forEach(function (r) { r.addEventListener('change', toggle); });
        toggle();
    })();
</script>
