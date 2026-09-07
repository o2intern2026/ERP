@extends('layouts.app')

@section('title', __('warehouse.reservations.title'))

@section('content')
    <h1>{{ __('warehouse.reservations.title') }}</h1>
    @if ($reservations->isEmpty())
        <p class="text-muted">{{ __('warehouse.reservations.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.stock.order') }}</th><th>{{ __('warehouse.stock.client') }}</th><th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.location') }}</th><th class="num">{{ __('warehouse.stock.qty') }}</th><th>{{ __('warehouse.reservations.created_at') }}</th></tr></thead>
            <tbody>
            @foreach ($reservations as $r)
                <tr><td>#{{ $r->order_id }} / {{ $r->order_line_id }}</td><td>{{ $r->stockUnit->asnLine->asn->client->name }}</td><td><a href="{{ route('warehouse.stock.show', $r->stockUnit) }}">{{ $r->stockUnit->label_code }}</a></td><td>{{ $r->stockUnit->location?->full_code }}</td><td class="num">{{ $r->qty }}</td><td>{{ $r->created_at->format('Y-m-d H:i') }}</td></tr>
            @endforeach
            </tbody>
        </table>
        {{ $reservations->links() }}
    @endif
@endsection
