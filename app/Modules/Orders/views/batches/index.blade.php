@extends('layouts.app')

@section('title', __('orders.batches.title'))

@section('content')
    <h1>{{ __('orders.batches.title') }}</h1>
    <p class="text-muted"><small>{{ __('orders.batches.hint') }}</small></p>
    <form method="get" class="grid">
        <input type="text" name="ref" class="scan" value="{{ $reference }}" placeholder="{{ __('orders.batches.reference') }}" autofocus>
        <button type="submit">{{ __('orders.batches.search') }}</button>
    </form>

    @if ($reference !== '' && $asns->isEmpty())
        <p>{{ __('orders.batches.not_found', ['ref' => $reference]) }}</p>
    @elseif ($asns->isNotEmpty())
        <div class="grid">
            <article>
                <header>{{ __('orders.batches.asn') }}</header>
                @foreach ($asns as $asn)<p><strong>{{ $asn->asn_no }}</strong> · {{ __('warehouse.asn_statuses.'.$asn->status) }} · {{ __('warehouse.inbound_types.'.$asn->inbound_type) }}</p>@endforeach
                @if ($containers->isNotEmpty())<p>{{ __('orders.batches.containers') }}: {{ $containers->pluck('container_no')->implode(', ') }}</p>@endif
            </article>
            <article>
                <header>{{ __('orders.batches.orders') }}: {{ $totals['orders'] }}</header>
                <p>{{ __('orders.batches.cartons') }}: {{ $totals['cartons'] }} · {{ __('orders.batches.shipped') }}: {{ $totals['shipped'] }}</p>
                <p>{{ __('orders.batches.by_status') }}: @foreach ($totals['by_status'] as $status => $n)<span class="badge" data-tone="muted">{{ __('orders.statuses.operational.'.$status) }} × {{ $n }}</span> @endforeach</p>
                <p>{{ __('orders.batches.revenue') }}: <strong>{{ $totals['revenue_cents'] === null ? __('orders.batches.revenue_pending') : \App\Support\Money::cents((int) $totals['revenue_cents'])->format() }}</strong></p>
            </article>
        </div>
        @if ($orders->isEmpty())
            <p class="text-muted">{{ __('orders.batches.no_orders') }}</p>
        @else
            <div class="overflow-auto">
                <table class="dense">
                    <thead><tr><th>{{ __('orders.fields.order_no') }}</th><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.fields.job') }}</th><th>{{ __('orders.fields.consignment_mark') }}</th><th>{{ __('orders.fields.destination') }}</th><th>{{ __('orders.fields.requested_date') }}</th><th>{{ __('orders.fields.operational_status') }}</th><th>{{ __('orders.fields.fulfilment_status') }}</th><th class="num">{{ __('orders.batches.cartons') }}</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr>
                                <td><a href="{{ route('orders.show', $order) }}">{{ $order->order_no }}</a></td>
                                <td>{{ $order->client->name }}</td>
                                <td>{{ $order->job->job_no }}</td>
                                <td>{{ $order->consignment_mark }}</td>
                                <td>{{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }}</td>
                                <td>{{ $order->requested_date->format('Y-m-d') }}</td>
                                <td>{{ __('orders.statuses.operational.'.$order->operational_status) }}</td>
                                <td>{{ __('orders.statuses.fulfilment.'.$order->fulfilment_status) }}</td>
                                <td class="num">{{ $order->lines->sum('carton_qty') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
