@extends('layouts.app')

@section('title', __('orders.queue.title'))

@section('content')
    <h1>{{ __('orders.queue.title') }}</h1>
    <p class="text-muted"><small>{{ __('orders.queue.hint') }}</small></p>
    <nav aria-label="{{ __('orders.queue.title') }}">
        <ul>
            @foreach ($views as $v)
                <li><a href="{{ route('orders.queue', ['view' => $v]) }}" @if ($v === $view) aria-current="page" @endif>{{ __('orders.queue.views.'.$v) }} <span class="badge" data-tone="{{ $counts[$v] ? ($v === 'financial_hold' || $v === 'exceptions' ? 'danger' : 'warn') : 'muted' }}">{{ $counts[$v] }}</span></a></li>
            @endforeach
        </ul>
    </nav>
    @if ($orders->isEmpty())
        <p class="text-muted">{{ __('orders.queue.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead><tr>
                    <th>{{ __('orders.fields.order_no') }}</th>
                    <th>{{ __('orders.fields.client') }}</th>
                    <th>{{ __('orders.fields.job') }}</th>
                    <th>{{ __('orders.fields.order_type') }}</th>
                    <th>{{ __('orders.fields.destination') }}</th>
                    <th>{{ __('orders.fields.requested_date') }}</th>
                    <th>{{ __('orders.fields.operational_status') }}</th>
                    <th>{{ __('orders.fields.fulfilment_status') }}</th>
                    <th>{{ __('orders.queue.holds') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a href="{{ route('orders.show', $order) }}">{{ $order->order_no }}</a></td>
                            <td>{{ $order->client->name }}</td>
                            <td>{{ $order->job->job_no }}</td>
                            <td>{{ __('orders.types.'.$order->order_type) }}</td>
                            <td>{{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }}</td>
                            <td>{{ $order->requested_date->format('Y-m-d') }}</td>
                            <td>{{ __('orders.statuses.operational.'.$order->operational_status) }}</td>
                            <td>{{ __('orders.statuses.fulfilment.'.$order->fulfilment_status) }}</td>
                            <td>@foreach ($holdTypes[$order->id] ?? [] as $type)<span class="badge" data-tone="{{ $type === 'financial' ? 'danger' : 'warn' }}">{{ __('orders.holds.types.'.$type) }}</span> @endforeach</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection
