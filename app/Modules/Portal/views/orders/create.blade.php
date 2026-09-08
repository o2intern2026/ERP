@extends('layouts.app')

@section('title', __('portal.create.title'))

@section('content')
    <p><a href="{{ route('portal.index') }}">← {{ __('portal.actions.back') }}</a></p>
    <h1>{{ __('portal.create.title') }}</h1>
    <p class="text-muted"><small>{{ __('portal.create.hint') }}</small></p>

    @if ($errors->any())
        <article><strong>{{ __('portal.validation.heading') }}</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <form method="post" action="{{ route('portal.orders.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('portal.fields.order_type') }}
                <select name="order_type" id="order-type" required>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(old('order_type', 'from_stock') === $type)>{{ __('orders.types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('portal.fields.reference') }}<input name="external_ref" value="{{ old('external_ref') }}"></label>
            <label>{{ __('portal.fields.consignment_mark') }}<input name="consignment_mark" value="{{ old('consignment_mark') }}"></label>
            <label>{{ __('portal.fields.fba_reference') }}<input name="fba_reference" value="{{ old('fba_reference') }}"></label>
        </div>

        <h2>{{ __('portal.sections.delivery') }}</h2>
        <label>{{ __('portal.fields.client_address') }}
            <select name="client_address_id" id="client-address">
                <option value="">{{ __('portal.create.enter_manually') }}</option>
                @foreach ($addresses as $address)
                    <option value="{{ $address->id }}" data-contact-name="{{ $address->contact_name ?: $address->label }}" data-phone="{{ $address->phone }}" data-address="{{ $address->address }}" data-suburb="{{ $address->suburb }}" data-state="{{ $address->state }}" data-postcode="{{ $address->postcode }}" data-address-type="{{ $address->address_type }}" data-instructions="{{ $address->default_instructions }}" @selected((int) old('client_address_id') === $address->id)>{{ $address->label }} — {{ $address->suburb }}, {{ $address->state }}</option>
                @endforeach
            </select>
            <small>{{ __('portal.create.address_hint') }}</small>
        </label>
        <div class="grid">
            <label>{{ __('portal.fields.deliver_to_name') }}<input id="deliver-to-name" name="deliver_to_name" value="{{ old('deliver_to_name') }}" required></label>
            <label>{{ __('portal.fields.deliver_to_phone') }}<input id="deliver-to-phone" name="deliver_to_phone" value="{{ old('deliver_to_phone') }}"></label>
            <label>{{ __('portal.fields.address_type') }}
                <select id="deliver-to-address-type" name="deliver_to_address_type" required>
                    @foreach ($addressTypes as $type)
                        <option value="{{ $type }}" @selected(old('deliver_to_address_type', 'business') === $type)>{{ __('orders.address_types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="suggest-wrap">
            <label>{{ __('portal.fields.address') }}<input id="deliver-to-address" name="deliver_to_address" value="{{ old('deliver_to_address') }}" required autocomplete="off">
                <small>{{ __('portal.create.suggest_hint') }}</small>
            </label>
        </div>
        @include('orders::partials.address-suggest', ['suggestUrl' => route('portal.addresses.suggest')])
        <div class="grid">
            <label>{{ __('portal.fields.suburb') }}<input id="deliver-to-suburb" name="deliver_to_suburb" value="{{ old('deliver_to_suburb') }}" required></label>
            <label>{{ __('portal.fields.state') }}
                <select id="deliver-to-state" name="deliver_to_state" required>
                    <option value="">{{ __('portal.actions.select') }}</option>
                    @foreach ($states as $state)<option value="{{ $state }}" @selected(old('deliver_to_state') === $state)>{{ $state }}</option>@endforeach
                </select>
            </label>
            <label>{{ __('portal.fields.postcode') }}<input id="deliver-to-postcode" name="deliver_to_postcode" value="{{ old('deliver_to_postcode') }}" required></label>
        </div>
        <label>{{ __('portal.fields.delivery_instructions') }}<textarea id="delivery-instructions" name="delivery_instructions" rows="2">{{ old('delivery_instructions') }}</textarea></label>
        <div class="grid">
            <label>{{ __('portal.fields.requested_date') }}<input type="date" name="requested_date" value="{{ old('requested_date') }}" required></label>
            <label>{{ __('portal.fields.service_level') }}
                <select name="service_level" required>
                    @foreach ($serviceLevels as $level)<option value="{{ $level }}" @selected(old('service_level', 'standard') === $level)>{{ __('orders.service_levels.'.$level) }}</option>@endforeach
                </select>
            </label>
        </div>
        @include('orders::partials.tailgate', ['tailgateThresholdKg' => $tailgateThresholdKg])

        <fieldset id="pickup-fields" hidden>
            <legend>{{ __('portal.sections.pickup') }}</legend>
            <div class="grid">
                <label>{{ __('portal.pickup.name') }}<input name="pickup_name" value="{{ old('pickup_name') }}"></label>
                <label>{{ __('portal.pickup.phone') }}<input name="pickup_phone" value="{{ old('pickup_phone') }}"></label>
            </div>
            <label>{{ __('portal.pickup.address') }}<input name="pickup_address_line" value="{{ old('pickup_address_line') }}"></label>
            <div class="grid">
                <label>{{ __('portal.fields.suburb') }}<input name="pickup_suburb" value="{{ old('pickup_suburb') }}"></label>
                <label>{{ __('portal.fields.state') }}
                    <select name="pickup_state"><option value="">{{ __('portal.actions.select') }}</option>@foreach ($states as $state)<option value="{{ $state }}" @selected(old('pickup_state') === $state)>{{ $state }}</option>@endforeach</select>
                </label>
                <label>{{ __('portal.fields.postcode') }}<input name="pickup_postcode" value="{{ old('pickup_postcode') }}"></label>
            </div>
            <h3>{{ __('portal.pickup.packages_title') }}</h3>
            @include('orders::partials.declared-packages')
        </fieldset>

        <h2>{{ __('portal.sections.goods') }}</h2>
        @include('orders::partials.goods-lines', ['prefix' => 'portal', 'extended' => false])

        <button type="submit">{{ __('portal.actions.submit_order') }}</button>
    </form>

    <script>
        (() => {
            const book = document.getElementById('client-address');
            const fields = {
                contactName: document.getElementById('deliver-to-name'), phone: document.getElementById('deliver-to-phone'),
                address: document.getElementById('deliver-to-address'), suburb: document.getElementById('deliver-to-suburb'),
                state: document.getElementById('deliver-to-state'), postcode: document.getElementById('deliver-to-postcode'),
                addressType: document.getElementById('deliver-to-address-type'), instructions: document.getElementById('delivery-instructions'),
            };
            book.addEventListener('change', () => {
                const option = book.selectedOptions[0];
                if (!option?.value) return;
                Object.entries(fields).forEach(([key, field]) => { field.value = option.dataset[key] ?? ''; });
            });
            const orderType = document.getElementById('order-type');
            const pickup = document.getElementById('pickup-fields');
            const toggleType = () => {
                const pure = orderType.value === 'pickup_deliver';
                pickup.hidden = !pure;
                document.querySelectorAll('.goods-required').forEach(el => { el.required = !pure; });
            };
            orderType.addEventListener('change', toggleType);
            toggleType();
        })();
    </script>
@endsection
