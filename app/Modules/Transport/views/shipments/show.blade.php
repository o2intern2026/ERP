@extends('layouts.app')

@section('title', $shipment->shipment_no)

@section('content')
    <p><a href="{{ route('transport.index') }}">{{ __('transport.shipments.back') }}</a></p>
    <h1>{{ $shipment->shipment_no }}</h1>

    <dl>
        <dt>{{ __('transport.shipments.job') }}</dt>
        <dd>{{ $shipment->job?->job_no ?? $shipment->job_id }}</dd>
        <dt>{{ __('transport.shipments.client') }}</dt>
        <dd>{{ $shipment->client->name }}</dd>
        <dt>{{ __('transport.shipments.order') }}</dt>
        <dd><a href="{{ route('transport.orders.margin', $shipment->order_id) }}">{{ $shipment->order_id }}</a></dd>
        <dt>{{ __('transport.shipments.type') }}</dt>
        <dd>{{ __('transport.shipment_types.'.$shipment->shipment_type) }}</dd>
        <dt>{{ __('transport.shipments.status') }}</dt>
        <dd>{{ __('transport.statuses.'.$shipment->status) }}</dd>
        <dt>{{ __('transport.shipments.tracking_number') }}</dt>
        <dd>{{ $shipment->tracking_number ?: __('transport.not_selected') }}</dd>
    </dl>

    {{-- 2026-09-10 audit: every action form below is gated by the same roles its controller accepts, so no role is offered a form the server refuses. --}}
    @if ($shipment->status === 'quote_confirmed' && $shipment->selectedQuote !== null)
        @role('admin|customer_service|dispatcher|transport_operator')
        <details open>
            <summary>{{ __('transport.booking.title') }}</summary>
            <form method="post" action="{{ route('transport.shipments.book', $shipment) }}">
                @csrf
                @if ($shipment->selectedQuote->source === 'manual')
                    <label>{{ __('transport.booking.reference') }}<input name="booking_reference" value="{{ old('booking_reference') }}" required></label>
                    <label>{{ __('transport.booking.tracking_number') }}<input name="tracking_number" value="{{ old('tracking_number') }}"></label>
                @elseif ($shipment->selectedQuote->source === 'transdirect')
                    <label>{{ __('transport.booking.pickup_date') }}<input type="date" name="pickup_date" value="{{ old('pickup_date') }}"></label>
                @endif
                <button type="submit">{{ __('transport.booking.submit') }}</button>
            </form>
            <small>{{ __('transport.booking.hold_hint') }}</small>
        </details>
        @endrole
    @endif

    <h2>{{ __('transport.costs.title') }}</h2>
    @if ($margin['cost_status'] === 'missing')
        <p>{{ __('transport.costs.missing') }}</p>
    @else
        <p>
            <strong>{{ __('transport.costs.formula') }}:</strong>
            {{ \App\Support\Money::cents($margin['revenue_cents'])->format() }} −
            {{ \App\Support\Money::cents($margin['payable_cost_cents'])->format() }} =
            {{ \App\Support\Money::cents($margin['margin_cents'])->format() }}
            <span class="badge" data-tone="{{ $margin['margin_is_estimate'] ? 'warn' : 'ok' }}">
                {{ __('transport.costs.statuses.'.$margin['cost_status']) }}
            </span>
        </p>
    @endif

    @if (in_array($shipment->status, ['quoting', 'quoted'], true) && $manualServices->isNotEmpty())
        @role('admin|customer_service|dispatcher|transport_operator')
        <details open>
            <summary>{{ __('transport.manual_quote.title') }}</summary>
            {{-- 2026-09-10 audit: only the stages whose booking request can be built are offered; otherwise the page says why. --}}
            @if ($manualQuoteStages === [])
                <p>{{ __('transport.manual_quote.details_unavailable_hint') }}</p>
            @else
            <form method="post" action="{{ route('transport.shipments.quotes.manual', $shipment) }}">
                @csrf
                <label>
                    {{ __('transport.manual_quote.carrier_service') }}
                    <select name="carrier_service_id" required>
                        @foreach ($manualServices as $service)
                            <option value="{{ $service->id }}" @selected((string) old('carrier_service_id') === (string) $service->id)>{{ $service->carrier->name }} — {{ __('transport.service_levels.'.$service->service_level) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>
                    {{ __('transport.manual_quote.stage') }}
                    <select name="quote_stage" required>
                        @foreach ($manualQuoteStages as $stage)
                            <option value="{{ $stage }}" @selected(old('quote_stage', in_array('final', $manualQuoteStages, true) ? 'final' : $manualQuoteStages[0]) === $stage)>{{ __('transport.quote_stages.'.$stage) }}</option>
                        @endforeach
                    </select>
                </label>
                @if (count($manualQuoteStages) < 2)
                    <small>{{ __('transport.manual_quote.stage_unavailable_hint', ['stages' => collect($manualQuoteStages)->map(fn (string $stage) => __('transport.quote_stages.'.$stage))->implode(' / ')]) }}</small>
                @endif
                <label>{{ __('transport.manual_quote.cost_cents') }}<input type="number" name="cost_cents" min="1" step="1" value="{{ old('cost_cents') }}" required></label>
                <label>{{ __('transport.manual_quote.customer_price_cents') }}<input type="number" name="customer_price_cents" min="1" step="1" value="{{ old('customer_price_cents') }}" required></label>
                <label>{{ __('transport.manual_quote.eta_days') }}<input type="number" name="eta_days" min="0" step="1" value="{{ old('eta_days', 3) }}" required></label>
                <button type="submit">{{ __('transport.manual_quote.submit') }}</button>
            </form>
            @endif
        </details>
        @endrole
    @endif

    @if ($shipment->selectedQuote?->source === 'own_fleet' && in_array($shipment->status, ['booked', 'dispatched', 'in_transit', 'delivered', 'failed'], true))
        @role('admin|dispatcher|transport_operator|finance')
        <details>
            <summary>{{ __('transport.costs.enter_own_fleet') }}</summary>
            <form method="post" action="{{ route('transport.shipments.own-fleet-cost.store', $shipment) }}">
                @csrf
                <label>
                    {{ __('transport.costs.actual_cost_cents') }}
                    <input type="number" name="cost_cents" min="0" step="1" value="{{ old('cost_cents', $shipment->carrierCost?->actual_cost_cents) }}" required>
                </label>
                <label>
                    {{ __('transport.costs.note') }}
                    <textarea name="note" maxlength="1000" required>{{ old('note', $shipment->carrierCost?->note) }}</textarea>
                </label>
                <button type="submit">{{ __('transport.costs.save') }}</button>
            </form>
        </details>
        @endrole
    @endif

    <p>
        <a role="button" href="{{ route('transport.shipments.consignment-note', $shipment) }}">
            {{ __('transport.consignment_note.download') }}
        </a>
        {{-- 2026-09-10 audit: the print button appears only when ShipmentLabelService can produce the document (final selected quote + own fleet, an archived waybill, or a booked gateway that issues labels). --}}
        @role('admin|customer_service|dispatcher|transport_operator')
            @if ($canPrintLabel)
                <a role="button" href="{{ route('transport.shipments.label', $shipment) }}">
                    {{ $shipment->selectedQuote->source === 'own_fleet'
                        ? __('transport.labels.print_own')
                        : __('transport.labels.print_waybill') }}
                </a>
            @elseif ($shipment->selectedQuote !== null)
                <small>{{ $shipment->selectedQuote->source === 'manual' && $shipment->selectedQuote->quote_stage === 'final' && $shipment->selectedQuote->status === 'selected'
                    ? __('transport.labels.manual_unavailable')
                    : __('transport.labels.not_ready') }}</small>
            @endif
        @endrole
    </p>

    @if ($shipment->status === 'failed')
        @role('admin|customer_service|dispatcher|transport_operator')
        <form method="post" action="{{ route('transport.shipments.redelivery.store', $shipment) }}">
            @csrf
            <button type="submit">{{ __('transport.redelivery.create') }}</button>
        </form>
        @endrole
    @endif

    @php($deliveredPod = $shipment->pods->firstWhere('delivered_at', '!=', null))
    @if ($deliveredPod)
        <p>{{ __('transport.carrier_pod.available', [
            'recipient' => $deliveredPod->recipient_name,
            'time' => $deliveredPod->delivered_at->format('Y-m-d H:i'),
        ]) }}</p>
    @elseif ($shipment->selectedQuote !== null && $shipment->selectedQuote->source !== 'own_fleet')
        @role('admin|customer_service|dispatcher|transport_operator')
        <details>
            <summary>{{ __('transport.carrier_pod.upload') }}</summary>
            <form method="post" enctype="multipart/form-data" action="{{ route('transport.shipments.pod.store', $shipment) }}">
                @csrf
                <label>
                    {{ __('transport.driver.recipient_name') }}
                    <input name="recipient_name" value="{{ old('recipient_name') }}" maxlength="150" required>
                </label>
                <label>
                    {{ __('transport.carrier_pod.file') }}
                    <input type="file" name="pod_file" accept="application/pdf" required>
                </label>
                <button type="submit">{{ __('transport.carrier_pod.save') }}</button>
            </form>
        </details>
        @endrole
    @endif

    <h2>{{ __('transport.tracking.title') }}</h2>
    @if ($shipment->trackingEvents->isEmpty())
        <p>{{ __('transport.tracking.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr>
                <th>{{ __('transport.tracking.time') }}</th>
                <th>{{ __('transport.tracking.status') }}</th>
                <th>{{ __('transport.tracking.location') }}</th>
                <th>{{ __('transport.tracking.description') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($shipment->trackingEvents as $tracking)
                    <tr>
                        <td>{{ $tracking->occurred_at?->format('Y-m-d H:i') ?? $tracking->created_at->format('Y-m-d H:i') }}</td>
                        <td>{{ $tracking->status }}</td>
                        <td>{{ $tracking->location ?: __('transport.not_selected') }}</td>
                        <td>{{ $tracking->description ?: __('transport.not_selected') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @role('admin|customer_service|dispatcher|transport_operator')
    <details>
        <summary>{{ __('transport.extra_charges.title') }}</summary>
        <form method="post" action="{{ route('transport.shipments.extra-charges.store', $shipment) }}">
            @csrf
            <label>
                {{ __('transport.extra_charges.type') }}
                <select name="charge_type" required>
                    @foreach (\App\Modules\Transport\Services\ExtraChargeService::CHARGE_TYPES as $chargeType)
                        <option value="{{ $chargeType }}" @selected(old('charge_type') === $chargeType)>{{ __('transport.extra_charges.types.'.$chargeType) }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                {{ __('transport.extra_charges.quantity') }}
                <input type="number" name="qty" min="0.01" step="0.01" value="{{ old('qty', 1) }}" required>
            </label>
            <label>
                {{ __('transport.extra_charges.uom') }}
                <select name="uom" required>
                    @foreach (\App\Modules\Transport\Services\ExtraChargeService::UOMS as $uom)
                        <option value="{{ $uom }}" @selected(old('uom') === $uom)>{{ __('transport.extra_charges.uoms.'.$uom) }}</option>
                    @endforeach
                </select>
            </label>
            <label>
                {{ __('transport.extra_charges.cost_cents') }}
                <input type="number" name="cost_cents" min="0" step="1" value="{{ old('cost_cents') }}">
            </label>
            <label>
                {{ __('transport.extra_charges.note') }}
                <textarea name="note" maxlength="1000" required>{{ old('note') }}</textarea>
            </label>
            <button type="submit">{{ __('transport.extra_charges.submit') }}</button>
        </form>
    </details>
    @endrole

    <h2>{{ __('transport.quotes.title') }}</h2>
    @if ($shipment->quotes->isEmpty())
        <p>{{ __('transport.quotes.empty') }}</p>
    @else
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('transport.quotes.source') }}</th>
                    <th>{{ __('transport.quotes.carrier') }}</th>
                    <th>{{ __('transport.quotes.service_level') }}</th>
                    <th class="num">{{ __('transport.quotes.cost') }}</th>
                    <th class="num">{{ __('transport.quotes.customer_price') }}</th>
                    <th class="num">{{ __('transport.quotes.eta') }}</th>
                    <th>{{ __('transport.quotes.flags') }}</th>
                    <th>{{ __('transport.quotes.stage') }}</th>
                    <th>{{ __('transport.quotes.status') }}</th>
                    <th>{{ __('transport.quotes.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipment->quotes as $quote)
                    <tr>
                        <td>{{ __('transport.sources.'.$quote->source) }}</td>
                        <td>{{ $quote->carrier->name }}</td>
                        <td>{{ __('transport.service_levels.'.$quote->service_level) }}</td>
                        <td class="num">{{ \App\Support\Money::cents($quote->cost_cents)->format() }}</td>
                        <td class="num">{{ \App\Support\Money::cents($quote->customer_price_cents)->format() }}</td>
                        <td class="num">{{ trans_choice('transport.quotes.eta_days', $quote->eta_days, ['count' => $quote->eta_days]) }}</td>
                        <td>
                            @if ($quote->is_recommended)<span class="badge" data-tone="ok">{{ __('transport.flags.recommended') }}</span>@endif
                            @if ($quote->is_cheapest)<span class="badge">{{ __('transport.flags.cheapest') }}</span>@endif
                            @if ($quote->is_fastest)<span class="badge">{{ __('transport.flags.fastest') }}</span>@endif
                        </td>
                        <td>{{ __('transport.quote_stages.'.$quote->quote_stage) }}</td>
                        <td>
                            <span class="badge">{{ __('transport.quote_statuses.'.$quote->status) }}</span>
                            @if ($shipment->selected_quote_id === $quote->id)
                                <span class="badge" data-tone="ok">{{ __('transport.quotes.current_selection') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($shipment->status === 'quoted' && $quote->status === 'quoted' && $quote->expires_at->isFuture() && auth()->user()->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']))
                                <form method="post" action="{{ route('transport.shipments.quotes.select', [$shipment, $quote]) }}">
                                    @csrf
                                    <button type="submit">
                                        {{ $quote->quote_stage === 'final' ? __('transport.quotes.confirm') : __('transport.quotes.select_preliminary') }}
                                    </button>
                                </form>
                            @else
                                {{ __('transport.quotes.no_action') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
