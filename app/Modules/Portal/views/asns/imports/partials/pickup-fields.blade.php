{{-- CHANGE_REQUESTS #144: the pickup party of a 提货直送 list — every order of the list shares it (the same fields the single order form
     asks for that type) — and the 要求送达日 the orders carry (a 要求送达日 column on the sheet still wins per group). Rendered inside a
     fieldset that is hidden AND disabled while 库存出库配送 is chosen, so nothing of it submits then. --}}
<article class="kv-card" id="pickup-party">
    <strong>{{ __('portal.inbound.pickup.section') }}</strong>
    <p class="text-muted"><small>{{ __('portal.inbound.pickup.hint') }}</small></p>
    <div class="grid">
        <label>{{ __('portal.inbound.pickup.fields.name') }}<input type="text" name="pickup[name]" maxlength="255" value="{{ old('pickup.name') }}" required></label>
        <label>{{ __('portal.inbound.pickup.fields.phone') }}<input type="text" name="pickup[phone]" maxlength="40" value="{{ old('pickup.phone') }}"></label>
    </div>
    <div class="grid">
        <label>{{ __('portal.inbound.pickup.fields.address') }}<input type="text" name="pickup[address]" maxlength="255" value="{{ old('pickup.address') }}" required></label>
        <label>{{ __('portal.inbound.pickup.fields.suburb') }}<input type="text" name="pickup[suburb]" maxlength="100" value="{{ old('pickup.suburb') }}" required></label>
        <label>{{ __('portal.inbound.pickup.fields.state') }}
            <select name="pickup[state]" required><option value="">{{ __('portal.actions.select') }}</option>@foreach ($states as $state)<option value="{{ $state }}" @selected(old('pickup.state') === $state)>{{ $state }}</option>@endforeach</select>
        </label>
        <label>{{ __('portal.inbound.pickup.fields.postcode') }}<input type="text" name="pickup[postcode]" maxlength="4" inputmode="numeric" value="{{ old('pickup.postcode') }}" required></label>
    </div>
    <div class="grid">
        <label>{{ __('portal.inbound.pickup.fields.requested_date') }}<x-date-field name="requested_date" min="{{ today()->toDateString() }}" value="{{ old('requested_date', today()->addDays(3)->toDateString()) }}" required /></label>
    </div>
</article>
