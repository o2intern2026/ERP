@extends('layouts.app')

@section('title', __('warehouse.transfers.one', ['no' => $transfer->transfer_no]))

@section('content')
    <p><a href="{{ route('warehouse.transfers.index') }}">← {{ __('warehouse.transfers.title') }}</a></p>
    <h1><code>{{ $transfer->transfer_no }}</code> {!! \App\Support\Ui\StatusBadge::render('warehouse.transfers.statuses.', $transfer->status) !!}</h1>
    <article class="kv-card">
        <p style="margin:0">
            <strong>{{ __('warehouse.transfers.client') }}</strong> {{ $transfer->client?->name }} ·
            <strong>{{ __('warehouse.transfers.route') }}</strong> {{ $transfer->fromWarehouse?->code }} → {{ $transfer->toWarehouse?->code }} ·
            <strong>{{ __('warehouse.transfers.charge_to') }}</strong> {{ __('warehouse.transfers.charge_tos.'.$transfer->charge_to) }}
            <small class="text-muted">{{ __('warehouse.transfers.charge_to_hints.'.$transfer->charge_to) }}</small>
            @if ($transfer->notes)<br><small>{{ __('warehouse.transfers.notes') }}: {{ $transfer->notes }}</small>@endif
            @if ($transfer->dispatched_at)<br><small>{{ __('warehouse.transfers.dispatched_line', ['at' => $transfer->dispatched_at->format('Y-m-d H:i'), 'vehicle' => $transfer->vehicle ?: '—', 'driver' => $transfer->driver_name ?: '—']) }}</small>@endif
            @if ($transfer->received_at)<br><small>{{ __('warehouse.transfers.received_line', ['at' => $transfer->received_at->format('Y-m-d H:i')]) }}</small>@endif
        </p>
    </article>

    @error('dispatch')<p><mark>{{ $message }}</mark></p>@enderror
    @error('receive')<p><mark>{{ $message }}</mark></p>@enderror

    <h2>{{ __('warehouse.transfers.lines') }} <small class="text-muted">{{ __('warehouse.transfers.lines_summary', ['pallets' => $transfer->lines->pluck('pallet_id')->filter()->unique()->count(), 'units' => $transfer->lines->count(), 'cartons' => (int) $transfer->lines->sum('qty')]) }}</small></h2>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.pallet') }}</th><th>{{ __('warehouse.stock.mark') }}</th><th class="num">{{ __('warehouse.transfers.cartons') }}</th><th>{{ __('warehouse.transfers.from_location') }}</th><th>{{ __('warehouse.transfers.now_at') }}</th></tr></thead>
        <tbody>
        @foreach ($transfer->lines as $line)
            <tr>
                <td><a href="{{ route('warehouse.stock.show', $line->stock_unit_id) }}"><code>{{ $line->stockUnit?->label_code }}</code></a></td>
                <td>{{ $line->pallet?->pallet_no ?? '—' }}</td>
                <td>{{ $line->stockUnit?->asnLine?->consignment_mark }} {{ $line->stockUnit?->asnLine?->description }}</td>
                <td class="num">{{ $line->qty }}</td>
                <td><code>{{ $line->fromLocation?->full_code }}</code></td>
                <td><code>{{ $line->stockUnit?->location?->full_code }}</code></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($transfer->status === 'draft')
        @role('admin|warehouse_supervisor|dispatcher')
            <h2>{{ __('warehouse.transfers.dispatch_title') }}</h2>
            <form method="post" action="{{ route('warehouse.transfers.dispatch', $transfer) }}" id="transfer-dispatch" class="grid">
                @csrf
                <input type="text" name="vehicle" maxlength="100" placeholder="{{ __('warehouse.transfers.vehicle') }}" value="{{ old('vehicle') }}">
                <input type="text" name="driver_name" maxlength="100" placeholder="{{ __('warehouse.transfers.driver') }}" value="{{ old('driver_name') }}">
                <input type="text" name="notes" maxlength="255" placeholder="{{ __('warehouse.transfers.notes') }}" value="{{ old('notes', $transfer->notes) }}">
                <button type="submit">{{ __('warehouse.transfers.dispatch_submit') }}</button>
            </form>
            <p class="text-muted"><small>{{ __('warehouse.transfers.dispatch_hint') }}</small></p>
            <form method="post" action="{{ route('warehouse.transfers.cancel', $transfer) }}" id="transfer-cancel">
                @csrf
                <button type="submit" class="secondary outline">{{ __('warehouse.transfers.cancel_submit') }}</button>
            </form>
        @endrole
    @elseif ($transfer->status === 'dispatched')
        @role('admin|warehouse_supervisor|warehouse_operator')
            <h2>{{ __('warehouse.transfers.receive_title', ['warehouse' => $transfer->toWarehouse?->code]) }}</h2>
            @if ($receivingLocations->isEmpty())
                <p><mark>{{ __('warehouse.locations.no_receiving', ['code' => $transfer->toWarehouse?->code]) }}</mark></p>
            @else
                <form method="post" action="{{ route('warehouse.transfers.receive', $transfer) }}" id="transfer-receive" class="grid">
                    @csrf
                    <select name="receiving_location_id" required>@foreach ($receivingLocations as $l)<option value="{{ $l->id }}">{{ $l->full_code }}</option>@endforeach</select>
                    <button type="submit">{{ __('warehouse.transfers.receive_submit') }}</button>
                </form>
                <p class="text-muted"><small>{{ __('warehouse.transfers.receive_hint') }}</small></p>
            @endif
        @endrole
    @elseif ($transfer->status === 'received')
        <p>{{ __('warehouse.transfers.received_next') }} <a href="{{ route('warehouse.putaway.index') }}">{{ __('warehouse.nav_putaway') }} →</a></p>
    @endif
@endsection
