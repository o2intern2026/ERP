@extends('layouts.app')

@section('title', __('warehouse.putaway.title'))

@section('content')
    <h1>{{ __('warehouse.putaway.title') }} · {{ __('warehouse.putaway.pending') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.putaway.hint') }}</small></p>
    @if ($units->isEmpty())
        <p class="text-muted">{{ __('warehouse.putaway.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.client') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.stock.unit_type') }}</th><th class="num">{{ __('warehouse.stock.on_hand') }}</th><th>{{ __('warehouse.stock.condition') }}</th><th>{{ __('warehouse.stock.location') }}</th><th>{{ __('warehouse.putaway.location_code') }}</th></tr></thead>
            <tbody>
            @foreach ($units as $u)
                <tr @if ($highlight === $u->id) style="outline:2px solid var(--erp-warn)" @endif>
                    <td><code>{{ $u->label_code }}</code></td><td>{{ $u->asnLine->asn->client->name }}</td><td>{{ $u->asnLine->description }}</td><td>{{ __('warehouse.unit_types.'.$u->unit_type) }}</td><td class="num">{{ $u->qty_on_hand }}</td>
                    <td><span class="badge" data-tone="{{ $u->condition === 'good' ? 'ok' : 'danger' }}">{{ __('warehouse.conditions.'.$u->condition) }}</span></td><td>{{ $u->location?->full_code }}</td>
                    <td>
                        <form method="post" action="{{ route('warehouse.putaway.store', $u) }}" class="inline">
                            @csrf
                            <input type="text" name="location_code" class="scan" list="locations-{{ $u->warehouse_id }}" placeholder="{{ $u->warehouse->code }}-A-01-01" required style="width:12rem">
                            <button type="submit">{{ __('warehouse.putaway.do') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @foreach ($locations as $warehouseId => $list)
            <datalist id="locations-{{ $warehouseId }}">@foreach ($list as $loc)<option value="{{ $loc->full_code }}">{{ __('warehouse.location_types.'.$loc->type) }}</option>@endforeach</datalist>
        @endforeach
        {{ $units->links() }}
    @endif
@endsection
