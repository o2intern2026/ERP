@extends('layouts.app')

@section('title', __('portal.create.title'))

@section('content')
    <p><a href="{{ route('portal.index') }}">← {{ __('portal.actions.back') }}</a></p>
    <h1>{{ __('portal.create.title') }}</h1>
    <p class="text-muted"><small>{{ __('portal.create.hint') }}</small></p>
    {{-- Validation errors are rendered once, by layouts/partials/flash (2026-09-10 audit: the page used to print the list twice). --}}

    <form method="post" action="{{ route('portal.orders.preview') }}" id="portal-order-form">
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
            <label>{{ __('portal.fields.requested_date') }}<input type="date" name="requested_date" value="{{ old('requested_date') }}" min="{{ today()->toDateString() }}" required></label>
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

        @if ($preview !== null)
            <article id="estimate-preview">
                <header><strong>{{ __('portal.estimate.preview_title') }}</strong></header>
                <table>
                    <thead><tr><th>{{ __('portal.estimate.preview_item') }}</th><th class="num">{{ __('portal.estimate.preview_qty') }}</th><th class="num">{{ __('portal.estimate.preview_amount') }}</th></tr></thead>
                    <tbody>
                        @foreach ($preview['lines'] as $line)
                            <tr>
                                <td>{{ $line['description'] }} <span class="text-muted"><small>{{ $line['charge_code'] }}</small></span></td>
                                <td class="num">{{ rtrim(rtrim(number_format($line['qty'], 2), '0'), '.') }} {{ $line['uom'] ? (\Illuminate\Support\Facades\Lang::has('orders.estimate.uoms.'.$line['uom']) ? __('orders.estimate.uoms.'.$line['uom']) : $line['uom']) : '' }}</td>
                                <td class="num">{{ $line['missing'] ? __('portal.estimate.preview_poa') : \App\Support\Money::cents($line['amount_cents'])->format() }}</td>
                            </tr>
                        @endforeach
                        {{-- CR #118: the transport options priced for this order, chosen here by the client — the estimate and the freight on one screen. --}}
                        @php($transportOptions = $transportOptions ?? [])
                        @php($chosen = collect($transportOptions)->firstWhere('key', $transportChoice ?? '') ?? collect($transportOptions)->firstWhere('is_recommended', true) ?? ($transportOptions[0] ?? null))
                        <tr><td colspan="3">
                            <strong>{{ __('portal.estimate.transport_title') }}</strong> <small class="text-muted">{{ __('portal.estimate.transport_hint') }}</small>
                            @if ($transportOptions === [])
                                <br><small class="text-muted">{{ __('portal.estimate.transport_none.'.($transportReason ?? 'none')) }}</small>
                            @else
                                <table class="dense" id="transport-options" style="margin:.4rem 0 0">
                                    <thead><tr><th>{{ __('portal.estimate.transport_choose') }}</th><th>{{ __('portal.quotes.fields.carrier') }}</th><th>{{ __('portal.quotes.fields.service_level') }}</th><th>{{ __('portal.quotes.fields.eta') }}</th><th class="num">{{ __('portal.quotes.fields.price') }}</th><th>{{ __('portal.quotes.fields.flags') }}</th></tr></thead>
                                    <tbody>
                                    @foreach ($transportOptions as $option)
                                        <tr>
                                            <td><input type="radio" name="transport_choice" value="{{ $option['key'] }}" data-price="{{ (int) $option['customer_price_cents'] }}" aria-label="{{ $option['key'] }}" @checked($chosen && $option['key'] === $chosen['key'])></td>
                                            <td>{{ $option['carrier_name'] ?: __('orders.estimate.sources.'.$option['source']) }}</td>
                                            <td>{{ __('orders.service_levels.'.$option['service_level']) }}</td>
                                            <td>{{ $option['eta_days'] === null ? __('portal.not_provided') : __('orders.estimate.eta_days', ['days' => $option['eta_days']]) }}</td>
                                            <td class="num">{{ \App\Support\Money::cents((int) $option['customer_price_cents'])->format() }}</td>
                                            <td>
                                                @if ($option['is_recommended'])<span class="badge" data-tone="ok">{{ __('orders.estimate.freight_flags.recommended') }}</span>@endif
                                                @if ($option['is_cheapest'])<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.cheapest') }}</span>@endif
                                                @if ($option['is_fastest'])<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.fastest') }}</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </td></tr>
                    </tbody>
                    <tfoot>
                        @php($freightCents = $chosen ? (int) $chosen['customer_price_cents'] : 0)
                        @php($freightGst = (int) round($freightCents * 0.10))
                        <tr><td colspan="2">{{ __('portal.estimate.preview_services') }}</td><td class="num">{{ \App\Support\Money::cents($preview['subtotal_cents'])->format() }}</td></tr>
                        <tr><td colspan="2">{{ __('portal.estimate.preview_freight_selected') }}</td><td class="num" id="freight-cell">{{ $chosen ? \App\Support\Money::cents($freightCents)->format() : __('portal.estimate.preview_freight_pending') }}</td></tr>
                        <tr><td colspan="2">{{ __('portal.estimate.preview_subtotal') }}</td><td class="num" id="subtotal-cell" data-base="{{ $preview['subtotal_cents'] }}">{{ \App\Support\Money::cents($preview['subtotal_cents'] + $freightCents)->format() }}</td></tr>
                        <tr><td colspan="2">{{ __('portal.estimate.preview_gst') }}</td><td class="num" id="gst-cell" data-base="{{ $preview['gst_cents'] }}">{{ \App\Support\Money::cents($preview['gst_cents'] + $freightGst)->format() }}</td></tr>
                        <tr><td colspan="2"><strong>{{ __('portal.estimate.preview_total') }}</strong></td><td class="num"><strong id="total-cell" data-base="{{ $preview['total_cents'] }}">{{ \App\Support\Money::cents($preview['total_cents'] + $freightCents + $freightGst)->format() }}</strong></td></tr>
                    </tfoot>
                </table>
                @if ($preview['unpriced'])<p class="text-muted"><small>{{ __('portal.estimate.preview_unpriced') }}</small></p>@endif
                <p class="text-muted"><small>{{ __('portal.estimate.preview_hint') }}</small></p>
            </article>
        @endif

        {{-- Tester feedback #10: the form defaults to "获取估价"; "确认提交订单" only appears once an estimate is on screen and hides again on any change. --}}
        <div class="grid" id="order-actions">
            <button type="submit" formaction="{{ route('portal.orders.preview') }}" class="{{ $preview !== null ? 'secondary' : '' }}" id="get-estimate">{{ __('portal.actions.get_estimate') }}</button>
            <button type="submit" formaction="{{ route('portal.orders.store') }}" id="confirm-submit" @if ($preview === null) hidden @endif>{{ __('portal.actions.confirm_submit') }}</button>
        </div>
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
                pickup.disabled = !pure; // 2026-09-10 audit: a hidden fieldset still submits its 包裹 rows; a disabled one does not
                document.querySelectorAll('.goods-required').forEach(el => { el.required = !pure; });
            };
            orderType.addEventListener('change', toggleType);
            // Rows added with 添加货物行 are rendered with `required`; re-apply the order-type rule to them (this listener runs after the partial's own).
            document.getElementById('add-goods-line')?.addEventListener('click', toggleType);
            toggleType();

            const confirm = document.getElementById('confirm-submit');
            const form = confirm.closest('form');
            const invalidate = (e) => {
                if (e && e.target && e.target.closest && e.target.closest('#estimate-preview')) return; // CR #118: picking a transport option keeps the estimate on screen
                if (confirm.hidden) return;
                confirm.hidden = true;
                document.getElementById('get-estimate')?.classList.remove('secondary');
                document.getElementById('estimate-preview')?.remove();
            };
            form.addEventListener('input', invalidate);
            form.addEventListener('change', invalidate);
            form.addEventListener('click', (e) => { if (e.target.closest('button[type="button"]')) invalidate(); }); // add/remove line rows
            document.getElementById('estimate-preview')?.scrollIntoView({ block: 'center' });
            // CR #118: the totals follow the ticked transport option (freight + 10% GST on top of the warehouse fees).
            const fmt = (cents) => (cents < 0 ? '-' : '') + '$' + (Math.abs(cents) / 100).toLocaleString('en-AU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            document.querySelectorAll('#transport-options input[name="transport_choice"]').forEach((radio) => radio.addEventListener('change', () => {
                const freight = parseInt(radio.dataset.price, 10) || 0, gst = Math.round(freight * 0.10);
                const freightCell = document.getElementById('freight-cell'); if (freightCell) freightCell.textContent = fmt(freight);
                [['subtotal-cell', freight], ['gst-cell', gst], ['total-cell', freight + gst]].forEach(([id, extra]) => {
                    const cell = document.getElementById(id); if (cell) cell.textContent = fmt((parseInt(cell.dataset.base, 10) || 0) + extra);
                });
            }));
        })();
    </script>
@endsection
