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
    </dl>

    <p>
        <a role="button" href="{{ route('transport.shipments.consignment-note', $shipment) }}">
            {{ __('transport.consignment_note.download') }}
        </a>
    </p>

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
