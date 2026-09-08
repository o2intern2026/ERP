@extends('layouts.app')

@section('title', __('orders.create.title'))

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.actions.back') }}</a></p>
    <h1>{{ __('orders.create.title') }}</h1>

    @if ($errors->any())
        <article>
            <strong>{{ __('orders.validation.heading') }}</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </article>
    @endif

    <p class="text-muted"><small>{{ __('orders.estimate.form_hint') }}</small></p>

    <form method="post" action="{{ route('orders.store') }}">
        @csrf

        <h2>{{ __('orders.sections.instruction') }}</h2>
        <div class="grid">
            <label>{{ __('orders.fields.client') }}
                <select name="client_id" required>
                    <option value="">{{ __('orders.actions.select') }}</option>
                    @foreach ($clients as $client)
                        <option value="{{ $client->id }}" @selected((int) old('client_id') === $client->id)>{{ $client->code }} — {{ $client->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('orders.fields.job') }}
                <select name="job_id">
                    <option value="">{{ __('orders.pickup.new_job') }}</option>
                    @foreach ($jobs as $job)
                        <option value="{{ $job->id }}" @selected((int) old('job_id') === $job->id)>{{ $job->job_no }} — {{ $job->client->name }}</option>
                    @endforeach
                </select>
                <small>{{ __('orders.pickup.new_job_hint') }}</small>
            </label>
            <label>{{ __('orders.fields.order_type') }}
                <select name="order_type" id="order-type" required>
                    @foreach ($types as $type)
                        <option value="{{ $type }}" @selected(old('order_type', 'from_stock') === $type)>{{ __('orders.types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="grid">
            <label>{{ __('orders.fields.external_ref') }}<input name="external_ref" value="{{ old('external_ref') }}"></label>
            <label>{{ __('orders.fields.consignment_mark') }}<input name="consignment_mark" value="{{ old('consignment_mark') }}"></label>
            <label>{{ __('orders.fields.fba_reference') }}<input name="fba_reference" value="{{ old('fba_reference') }}"></label>
        </div>

        <div class="grid">
            <h2>{{ __('orders.sections.delivery') }}</h2>
            <p style="text-align:right"><a href="{{ route('orders.addresses.index') }}">{{ __('orders.actions.address_book') }}</a></p>
        </div>
        <label>{{ __('orders.fields.client_address') }}
            <select name="client_address_id" id="client-address">
                <option value="">{{ __('orders.addresses.actions.enter_manually') }}</option>
                @foreach ($addresses as $address)
                    <option
                        value="{{ $address->id }}"
                        data-client-id="{{ $address->client_id }}"
                        data-contact-name="{{ $address->contact_name ?: $address->label }}"
                        data-phone="{{ $address->phone }}"
                        data-address="{{ $address->address }}"
                        data-suburb="{{ $address->suburb }}"
                        data-state="{{ $address->state }}"
                        data-postcode="{{ $address->postcode }}"
                        data-address-type="{{ $address->address_type }}"
                        data-instructions="{{ $address->default_instructions }}"
                        @selected((int) old('client_address_id') === $address->id)
                    >{{ $address->label }} — {{ $address->suburb }}, {{ $address->state }}</option>
                @endforeach
            </select>
            <small>{{ __('orders.addresses.frequency_hint') }}</small>
        </label>
        <div class="grid">
            <label>{{ __('orders.fields.deliver_to_name') }}<input id="deliver-to-name" name="deliver_to_name" value="{{ old('deliver_to_name') }}" required></label>
            <label>{{ __('orders.fields.deliver_to_phone') }}<input id="deliver-to-phone" name="deliver_to_phone" value="{{ old('deliver_to_phone') }}"></label>
            <label>{{ __('orders.fields.address_type') }}
                <select id="deliver-to-address-type" name="deliver_to_address_type" required>
                    @foreach ($addressTypes as $type)
                        <option value="{{ $type }}" @selected(old('deliver_to_address_type', 'business') === $type)>{{ __('orders.address_types.'.$type) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <label>{{ __('orders.fields.address') }}<input id="deliver-to-address" name="deliver_to_address" value="{{ old('deliver_to_address') }}" required></label>
        <div class="grid">
            <label>{{ __('orders.fields.suburb') }}<input id="deliver-to-suburb" name="deliver_to_suburb" value="{{ old('deliver_to_suburb') }}" required></label>
            <label>{{ __('orders.fields.state') }}
                <select id="deliver-to-state" name="deliver_to_state" required>
                    <option value="">{{ __('orders.actions.select') }}</option>
                    @foreach ($states as $state)
                        <option value="{{ $state }}" @selected(old('deliver_to_state') === $state)>{{ $state }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('orders.fields.postcode') }}<input id="deliver-to-postcode" name="deliver_to_postcode" value="{{ old('deliver_to_postcode') }}" required></label>
        </div>
        <label>{{ __('orders.fields.delivery_instructions') }}
            <textarea id="delivery-instructions" name="delivery_instructions" rows="3">{{ old('delivery_instructions') }}</textarea>
        </label>
        <div class="grid">
            <label>{{ __('orders.fields.requested_date') }}<input type="date" name="requested_date" value="{{ old('requested_date') }}" required></label>
            <label>{{ __('orders.fields.service_level') }}
                <select name="service_level" required>
                    @foreach ($serviceLevels as $level)
                        <option value="{{ $level }}" @selected(old('service_level', 'standard') === $level)>{{ __('orders.service_levels.'.$level) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        {{-- A11b: pure transport orders carry a pickup address and declared packages instead of stock lines. --}}
        <fieldset id="pickup-fields" hidden>
            <legend>{{ __('orders.pickup.title') }}</legend>
            <p class="text-muted"><small>{{ __('orders.pickup.hint') }}</small></p>
            <div class="grid">
                <label>{{ __('orders.pickup.name') }}<input name="pickup_name" value="{{ old('pickup_name') }}"></label>
                <label>{{ __('orders.pickup.phone') }}<input name="pickup_phone" value="{{ old('pickup_phone') }}"></label>
            </div>
            <label>{{ __('orders.pickup.address') }}<input name="pickup_address_line" value="{{ old('pickup_address_line') }}"></label>
            <div class="grid">
                <label>{{ __('orders.pickup.suburb') }}<input name="pickup_suburb" value="{{ old('pickup_suburb') }}"></label>
                <label>{{ __('orders.pickup.state') }}
                    <select name="pickup_state">
                        <option value="">{{ __('orders.actions.select') }}</option>
                        @foreach ($states as $state)
                            <option value="{{ $state }}" @selected(old('pickup_state') === $state)>{{ $state }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('orders.pickup.postcode') }}<input name="pickup_postcode" value="{{ old('pickup_postcode') }}"></label>
            </div>
            <h3>{{ __('orders.pickup.packages_title') }}</h3>
            @for ($i = 0; $i < 3; $i++)
                <div class="grid">
                    @include('orders::partials.package-type-select', ['name' => "declared_packages[$i][package_type]", 'value' => old("declared_packages.$i.package_type", 'carton'), 'ariaLabel' => __('orders.pickup.package_type')])
                    <input type="number" min="1" name="declared_packages[{{ $i }}][qty]" placeholder="{{ __('orders.pickup.qty') }}" value="{{ old("declared_packages.$i.qty") }}">
                    <input type="number" min="0" step="0.001" name="declared_packages[{{ $i }}][weight_kg]" placeholder="{{ __('orders.pickup.weight_kg') }}" value="{{ old("declared_packages.$i.weight_kg") }}">
                    <input type="number" min="0" name="declared_packages[{{ $i }}][length_mm]" placeholder="{{ __('orders.fields.length_mm') }}" value="{{ old("declared_packages.$i.length_mm") }}">
                    <input type="number" min="0" name="declared_packages[{{ $i }}][width_mm]" placeholder="{{ __('orders.fields.width_mm') }}" value="{{ old("declared_packages.$i.width_mm") }}">
                    <input type="number" min="0" name="declared_packages[{{ $i }}][height_mm]" placeholder="{{ __('orders.fields.height_mm') }}" value="{{ old("declared_packages.$i.height_mm") }}">
                </div>
            @endfor
        </fieldset>

        <h2>{{ __('orders.sections.goods') }}</h2>
        <div class="grid">
            <label>{{ __('orders.fields.description_cn') }}<input name="lines[0][description_cn]" value="{{ old('lines.0.description_cn') }}"></label>
            <label>{{ __('orders.fields.description_en') }}<input name="lines[0][description_en]" value="{{ old('lines.0.description_en') }}"></label>
            <label>{{ __('orders.fields.package_type') }}
                @include('orders::partials.package-type-select', ['name' => 'lines[0][package_type]', 'value' => old('lines.0.package_type', 'carton'), 'class' => 'goods-required', 'required' => true])
            </label>
        </div>
        <div class="grid">
            <label>{{ __('orders.fields.carton_qty') }}<input type="number" min="1" name="lines[0][carton_qty]" class="goods-required" value="{{ old('lines.0.carton_qty', 1) }}" required></label>
            <label>{{ __('orders.fields.unit_qty') }}<input type="number" min="0" name="lines[0][unit_qty]" value="{{ old('lines.0.unit_qty') }}"></label>
            <label>{{ __('orders.fields.weight_kg') }}<input type="number" min="0" step="0.001" name="lines[0][actual_weight_kg]" value="{{ old('lines.0.actual_weight_kg') }}"></label>
        </div>
        <div class="grid">
            <label>{{ __('orders.fields.length_mm') }}<input type="number" min="0" name="lines[0][length_mm]" value="{{ old('lines.0.length_mm') }}"></label>
            <label>{{ __('orders.fields.width_mm') }}<input type="number" min="0" name="lines[0][width_mm]" value="{{ old('lines.0.width_mm') }}"></label>
            <label>{{ __('orders.fields.height_mm') }}<input type="number" min="0" name="lines[0][height_mm]" value="{{ old('lines.0.height_mm') }}"></label>
            <label>{{ __('orders.fields.cbm') }}<input type="number" min="0" step="0.0001" name="lines[0][cbm]" value="{{ old('lines.0.cbm') }}"></label>
        </div>

        <button type="submit">{{ __('orders.actions.save') }}</button>
    </form>

    <script>
        (() => {
            const client = document.querySelector('[name="client_id"]');
            const book = document.getElementById('client-address');
            const addressOptions = Array.from(book.options).slice(1);
            const fields = {
                contactName: document.getElementById('deliver-to-name'),
                phone: document.getElementById('deliver-to-phone'),
                address: document.getElementById('deliver-to-address'),
                suburb: document.getElementById('deliver-to-suburb'),
                state: document.getElementById('deliver-to-state'),
                postcode: document.getElementById('deliver-to-postcode'),
                addressType: document.getElementById('deliver-to-address-type'),
                instructions: document.getElementById('delivery-instructions'),
            };

            const filterAddresses = () => {
                const clientId = client.value;
                addressOptions.forEach(option => {
                    const unavailable = option.dataset.clientId !== clientId;
                    option.hidden = unavailable;
                    option.disabled = unavailable;
                });
                if (book.selectedOptions[0]?.disabled) book.value = '';
            };

            client.addEventListener('change', filterAddresses);
            book.addEventListener('change', () => {
                const option = book.selectedOptions[0];
                if (!option?.value) return;
                Object.entries(fields).forEach(([key, field]) => {
                    field.value = option.dataset[key] ?? '';
                });
            });
            filterAddresses();

            // A11b: pure transport orders show the pickup fieldset and do not require goods lines.
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
