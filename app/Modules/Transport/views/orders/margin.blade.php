@extends('layouts.app')

@section('title', __('transport.costs.order_title', ['order' => $orderNo]))

@section('content')
    <p><a href="{{ route('transport.index') }}">{{ __('transport.shipments.back') }}</a></p>
    <h1>{{ __('transport.costs.order_title', ['order' => $orderNo]) }}</h1>

    @if ($summary['shipments'] === [])
        <p class="text-muted">{{ __('transport.costs.order_no_shipments') }}</p>
    @elseif ($summary['margin_cents'] === null)
        <p><span class="badge" data-tone="warn">{{ __('transport.costs.statuses.missing') }}</span> {{ __('transport.costs.order_incomplete') }}</p>
    @else
        <article>
            <header>{{ __('transport.costs.formula') }}</header>
            <strong>
                {{ \App\Support\Money::cents($summary['revenue_cents'])->format() }} −
                {{ \App\Support\Money::cents($summary['payable_cost_cents'])->format() }} =
                {{ \App\Support\Money::cents($summary['margin_cents'])->format() }}
            </strong>
            <span class="badge" data-tone="{{ $summary['margin_is_estimate'] ? 'warn' : 'ok' }}">
                {{ __('transport.costs.statuses.'.$summary['cost_status']) }}
            </span>
        </article>
    @endif

    @if ($summary['shipments'] !== [])
    <table class="dense">
        <thead>
            <tr>
                <th>{{ __('transport.shipments.number') }}</th>
                <th class="num">{{ __('transport.costs.revenue') }}</th>
                <th class="num">{{ __('transport.costs.expected') }}</th>
                <th class="num">{{ __('transport.costs.actual') }}</th>
                <th class="num">{{ __('transport.costs.payable') }}</th>
                <th class="num">{{ __('transport.costs.margin') }}</th>
                <th>{{ __('transport.costs.status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($summary['shipments'] as $row)
                <tr>
                    <td><a href="{{ route('transport.shipments.show', $row['shipment_id']) }}">{{ $row['shipment_no'] }}</a></td>
                    <td class="num">{{ \App\Support\Money::cents($row['revenue_cents'])->format() }}</td>
                    <td class="num">{{ $row['expected_cost_cents'] === null ? __('transport.costs.pending') : \App\Support\Money::cents($row['expected_cost_cents'])->format() }}</td>
                    <td class="num">{{ $row['actual_cost_cents'] === null ? __('transport.costs.pending') : \App\Support\Money::cents($row['actual_cost_cents'])->format() }}</td>
                    <td class="num">{{ $row['payable_cost_cents'] === null ? __('transport.costs.pending') : \App\Support\Money::cents($row['payable_cost_cents'])->format() }}</td>
                    <td class="num">{{ $row['margin_cents'] === null ? __('transport.costs.pending') : \App\Support\Money::cents($row['margin_cents'])->format() }}</td>
                    <td>{{ __('transport.costs.statuses.'.$row['cost_status']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif
@endsection
