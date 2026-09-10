@extends('layouts.app')

@section('title', __('warehouse.snapshots.title'))

@section('content')
    <h1>{{ __('warehouse.snapshots.title') }} · {{ $date->toDateString() }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.snapshots.hint') }}</small></p>
    <form method="get" class="grid">
        <input type="date" name="date" value="{{ $date->toDateString() }}">
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($rows->isEmpty())
        <p class="text-muted">{{ __('warehouse.snapshots.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.stock.client') }}</th><th>{{ __('warehouse.asns.warehouse') }}</th><th class="num">{{ __('warehouse.snapshots.pallets') }}</th><th>{{ __('warehouse.snapshots.by_class') }}</th><th>{{ __('warehouse.snapshots.by_source') }}</th><th class="num">{{ __('warehouse.snapshots.carton_units') }}</th><th class="num">{{ __('warehouse.snapshots.cartons') }}</th><th class="num">{{ __('warehouse.snapshots.pickface_slots') }}</th><th class="num">{{ __('warehouse.snapshots.damaged') }}</th></tr></thead>
            <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>{{ $r['client'] }}</td><td>{{ $r['warehouse'] }}</td><td class="num">{{ $r['pallets'] }}</td>
                    <td>@foreach ($r['pallets_by_class'] as $k => $n)<span class="badge" data-tone="muted">{{ $k === 'poa' ? __('billing.rate_cards.poa') : __('warehouse.pallet_classes.'.$k) }} × {{ $n }}</span> @endforeach</td>
                    <td>@foreach ($r['pallets_by_source'] as $k => $n)<span class="badge" data-tone="muted">{{ $k === 'unknown' ? '—' : __('warehouse.pallet_sources.'.$k) }} × {{ $n }}</span> @endforeach</td>
                    <td class="num">{{ $r['carton_units'] }}</td><td class="num">{{ $r['cartons'] }}</td><td class="num">{{ $r['pickface_slots'] }}</td><td class="num">{{ $r['damaged_units'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
@endsection
