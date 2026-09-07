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
        <dd>{{ $shipment->order_id }}</dd>
        <dt>{{ __('transport.shipments.type') }}</dt>
        <dd>{{ __('transport.shipment_types.'.$shipment->shipment_type) }}</dd>
        <dt>{{ __('transport.shipments.status') }}</dt>
        <dd>{{ __('transport.statuses.'.$shipment->status) }}</dd>
        <dt>{{ __('transport.shipments.tracking_number') }}</dt>
        <dd>{{ $shipment->tracking_number ?: __('transport.not_selected') }}</dd>
    </dl>

    <p>
        <a role="button" href="{{ route('transport.shipments.consignment-note', $shipment) }}">
            {{ __('transport.consignment_note.download') }}
        </a>
        @if ($shipment->selectedQuote !== null)
            <a role="button" href="{{ route('transport.shipments.label', $shipment) }}">
                {{ $shipment->selectedQuote->source === 'own_fleet'
                    ? __('transport.labels.print_own')
                    : __('transport.labels.print_waybill') }}
            </a>
        @endif
    </p>

    @if ($shipment->status === 'failed')
        <form method="post" action="{{ route('transport.shipments.redelivery.store', $shipment) }}">
            @csrf
            <button type="submit">{{ __('transport.redelivery.create') }}</button>
        </form>
    @endif

    @php($deliveredPod = $shipment->pods->firstWhere('delivered_at', '!=', null))
    @if ($deliveredPod)
        <p>{{ __('transport.carrier_pod.available', [
            'recipient' => $deliveredPod->recipient_name,
            'time' => $deliveredPod->delivered_at->format('Y-m-d H:i'),
        ]) }}</p>
    @elseif ($shipment->selectedQuote !== null && $shipment->selectedQuote->source !== 'own_fleet')
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

    <details>
        <summary>{{ __('transport.extra_charges.title') }}</summary>
        <form method="post" action="{{ route('transport.shipments.extra-charges.store', $shipment) }}">
            @csrf
            <label>
                {{ __('transport.extra_charges.type') }}
                <select name="charge_type" required>
                    @foreach (\App\Modules\Transport\Services\ExtraChargeService::CHARGE_TYPES as $chargeType)
                        <option value="{{ $chargeType }}">{{ __('transport.extra_charges.types.'.$chargeType) }}</option>
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
                        <option value="{{ $uom }}">{{ __('transport.extra_charges.uoms.'.$uom) }}</option>
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
                            @if ($shipment->status === 'quoted' && $quote->status === 'quoted' && $quote->expires_at->isFuture())
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
