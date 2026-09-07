@extends('layouts.app')

@section('title', __('orders.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('orders.title') }}</h1>
        @if (auth()->user()->hasAnyRole(['admin', 'customer_service', 'dispatcher']))
            <p style="text-align:right">
                <a class="secondary" role="button" href="{{ route('orders.addresses.index') }}">{{ __('orders.actions.address_book') }}</a>
                <a class="secondary" role="button" href="{{ route('orders.imports.index') }}">{{ __('orders.actions.import') }}</a>
                <a role="button" href="{{ route('orders.create') }}">{{ __('orders.actions.create') }}</a>
            </p>
        @endif
    </header>

    <form method="get">
        <div class="grid">
            <select name="client_id" aria-label="{{ __('orders.fields.client') }}">
                <option value="">{{ __('orders.filters.all_clients') }}</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>
                @endforeach
            </select>
            <select name="status" aria-label="{{ __('orders.fields.operational_status') }}">
                <option value="">{{ __('orders.filters.all_statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('orders.statuses.operational.'.$status) }}</option>
                @endforeach
            </select>
            <select name="state" aria-label="{{ __('orders.fields.state') }}">
                <option value="">{{ __('orders.filters.all_states') }}</option>
                @foreach ($states as $state)
                    <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid">
            <input name="consignment_mark" value="{{ $filters['consignment_mark'] ?? '' }}" placeholder="{{ __('orders.fields.consignment_mark') }}">
            <input type="date" name="requested_date" value="{{ $filters['requested_date'] ?? '' }}" aria-label="{{ __('orders.fields.requested_date') }}">
            <button type="submit" class="secondary">{{ __('orders.actions.filter') }}</button>
        </div>
    </form>

    @if ($orders->isEmpty())
        <p>{{ __('orders.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead>
                    <tr>
                        <th>{{ __('orders.fields.order_no') }}</th>
                        <th>{{ __('orders.fields.client') }}</th>
                        <th>{{ __('orders.fields.job') }}</th>
                        <th>{{ __('orders.fields.consignment_mark') }}</th>
                        <th>{{ __('orders.fields.destination') }}</th>
                        <th>{{ __('orders.fields.requested_date') }}</th>
                        <th>{{ __('orders.fields.operational_status') }}</th>
                        <th>{{ __('orders.fields.billing_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a href="{{ route('orders.show', $order) }}">{{ $order->order_no }}</a></td>
                            <td>{{ $order->client->name }}</td>
                            <td>{{ $order->job->job_no }}</td>
                            <td>{{ $order->consignment_mark ?: __('orders.not_provided') }}</td>
                            <td>{{ $order->deliver_to_suburb }}, {{ $order->deliver_to_state }}</td>
                            <td>{{ $order->requested_date->format('Y-m-d') }}</td>
                            <td>{{ __('orders.statuses.operational.'.$order->operational_status) }}
                                @if (in_array('financial', $holdTypes[$order->id] ?? [], true))<span class="badge" data-tone="danger">{{ __('orders.holds.financial_badge') }}</span>@endif
                            </td>
                            <td>{{ __('orders.statuses.billing.'.$order->billing_status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection
