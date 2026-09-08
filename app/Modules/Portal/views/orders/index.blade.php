@extends('layouts.app')

@section('title', __('portal.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('portal.title') }}</h1>
        @if (auth()->user()->isClientUser())
            <p style="text-align:right"><a role="button" href="{{ route('portal.orders.create') }}">{{ __('portal.actions.create') }}</a></p>
        @endif
    </header>
    <p class="text-muted"><small>{{ __('portal.hint') }}</small></p>

    <form method="get">
        <div class="grid">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('portal.filters.search') }}" aria-label="{{ __('portal.filters.search') }}">
            <select name="status" aria-label="{{ __('portal.fields.status') }}">
                <option value="">{{ __('portal.filters.all_statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('orders.customer_statuses.'.$status) }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="{{ __('portal.filters.from') }}">
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="{{ __('portal.filters.to') }}">
            <button type="submit" class="secondary">{{ __('portal.actions.filter') }}</button>
        </div>
    </form>

    @if ($orders->isEmpty())
        <p>{{ __('portal.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead><tr>
                    <th>{{ __('portal.fields.order_no') }}</th>
                    <th>{{ __('portal.fields.reference') }}</th>
                    <th>{{ __('portal.fields.order_type') }}</th>
                    <th>{{ __('portal.fields.destination') }}</th>
                    <th>{{ __('portal.fields.requested_date') }}</th>
                    <th>{{ __('portal.fields.status') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a href="{{ route('portal.orders.show', $order) }}">{{ $order->order_no }}</a></td>
                            <td>{{ $order->external_ref ?: ($order->consignment_mark ?: __('portal.not_provided')) }}</td>
                            <td>{{ __('orders.types.'.$order->order_type) }}</td>
                            <td>{{ $order->deliver_to_suburb }}, {{ $order->deliver_to_state }}</td>
                            <td>{{ $order->requested_date->format('Y-m-d') }}</td>
                            <td><span class="badge" data-tone="{{ in_array($order->customerStatus(), ['delivered', 'invoiced'], true) ? 'ok' : ($order->customerStatus() === 'cancelled' ? 'muted' : 'warn') }}">{{ __('orders.customer_statuses.'.$order->customerStatus()) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection
