@extends('layouts.app')

@section('title', __('warehouse.map.title'))

@php
    use App\Modules\Warehouse\Http\Controllers\MapController;
    $cellW = 64; $cellH = 30; $labelW = 34; $top = 22;
    $slotState = function ($slot) use ($occupancy): string {
        if (! $slot->active) return 'inactive';
        return $occupancy->has($slot->id) ? $occupancy[$slot->id]->state : 'empty';
    };
    $tooltip = function ($slot) use ($occupancy): string {
        $row = $occupancy[$slot->id] ?? null;
        $text = $slot->full_code;
        if ($row !== null) {
            $text .= ' · '.__('warehouse.map.tooltip', ['units' => $row->units, 'cartons' => (int) $row->cartons, 'client' => $row->client_name ?? '—']);
            if ((int) $row->reserved > 0) $text .= ' · '.__('warehouse.map.reserved', ['cartons' => (int) $row->reserved]);
            if ((int) $row->clients > 1) $text .= ' · '.__('warehouse.map.clients', ['count' => $row->clients]);
        } else {
            $text .= ' · '.__('warehouse.map.states.'.($slot->active ? 'empty' : 'inactive'));
        }
        if ($slot->isBottom()) $text .= ' · '.__('warehouse.storage_tiers.bottom');
        return $text;
    };
@endphp

@section('content')
    <h1>{{ __('warehouse.map.title') }}@if ($warehouse) <small class="text-muted">{{ $warehouse->code }} · {{ $warehouse->name }}</small>@endif</h1>
    <p class="text-muted"><small>{{ __('warehouse.map.hint') }}</small></p>

    @if ($warehouse === null)
        <p>{{ __('warehouse.map.no_warehouse') }}</p>
    @else
        <form method="get" action="{{ route('warehouse.map.index') }}" class="grid" style="align-items:end">
            <label>{{ __('warehouse.locations.warehouse') }}
                <select name="warehouse_id" onchange="this.form.submit()">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($w->id === $warehouse->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
            </label>
            <label>{{ __('warehouse.locations.zone') }}
                <select name="zone" onchange="this.form.submit()">@foreach ($zones as $z)<option value="{{ $z['zone'] }}" @selected($z['zone'] === $zone)>{{ __('warehouse.map.zone_option', ['zone' => $z['zone'], 'occupied' => $z['occupied'], 'slots' => $z['slots'], 'percent' => $z['percent']]) }}</option>@endforeach</select>
            </label>
            <noscript><button type="submit" class="secondary">{{ __('warehouse.map.show') }}</button></noscript>
        </form>

        {{-- Zone thumbnails: one bar per rack zone, the selected one highlighted; the warehouse total on the right. --}}
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem" id="zone-thumbnails">
            @foreach ($zones as $z)
                <a href="{{ route('warehouse.map.index', ['warehouse_id' => $warehouse->id, 'zone' => $z['zone']]) }}" class="kv-card" style="text-decoration:none;min-width:9rem;padding:.5rem .8rem;margin:0;border:{{ $z['zone'] === $zone ? '2px solid #1e88e5' : '1px solid #cfd8dc' }}" data-zone="{{ $z['zone'] }}">
                    <strong>{{ __('warehouse.map.zone_label', ['zone' => $z['zone']]) }}</strong><br>
                    <svg width="120" height="10" viewBox="0 0 120 10" role="img" aria-label="{{ $z['percent'] }}%"><rect x="0" y="0" width="120" height="10" fill="{{ MapController::fill('empty') }}"/><rect x="0" y="0" width="{{ (int) round(1.2 * $z['percent']) }}" height="10" fill="{{ MapController::fill('occupied') }}"/></svg>
                    <small class="text-muted">{{ __('warehouse.map.zone_occupancy', ['occupied' => $z['occupied'], 'slots' => $z['slots'], 'percent' => $z['percent']]) }}</small>
                </a>
            @endforeach
            <span class="text-muted" style="align-self:center"><small>{{ __('warehouse.map.total', ['occupied' => $totals['occupied'], 'slots' => $totals['slots']]) }}</small></span>
        </div>

        <p id="map-legend">
            @foreach ($states as $state)
                <svg width="14" height="14" viewBox="0 0 14 14" style="vertical-align:middle"><rect x="1" y="1" width="12" height="12" fill="{{ MapController::fill($state) }}" stroke="#607d8b"/></svg> <small>{{ __('warehouse.map.states.'.$state) }}</small>&nbsp;&nbsp;
            @endforeach
            <svg width="14" height="14" viewBox="0 0 14 14" style="vertical-align:middle"><rect x="1" y="1" width="12" height="12" fill="{{ MapController::fill('empty') }}" stroke="#f9a825" stroke-width="3"/></svg> <small>{{ __('warehouse.storage_tiers.bottom') }}</small>
        </p>

        @if ($aisles === [])
            <p>{{ __('warehouse.map.no_racks', ['zone' => $zone]) }} <a href="{{ route('warehouse.locations.index') }}">{{ __('warehouse.map.generate_link') }}</a></p>
        @endif
        {{-- One elevation view per aisle side: bays along the x axis (walk direction), levels stacked (top level first), two slots per bay. --}}
        @foreach ($aisles as $aisle => $sides)
            <h2>{{ __('warehouse.map.aisle', ['aisle' => $aisle]) }}</h2>
            <div class="grid">
            @foreach ($sides as $side => $rack)
                @php($width = $labelW + count($rack['bays']) * $cellW + 4)
                @php($height = $top + count($rack['levels']) * $cellH + 4)
                <div>
                    <p style="margin:0 0 .2rem"><strong>{{ __('warehouse.map.side_'.$side) }}</strong> <small class="text-muted">{{ __('warehouse.map.side_hint_'.$side) }}</small></p>
                    <div class="overflow-auto">
                    <svg viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}" style="max-width:100%;height:auto;font-family:sans-serif" role="img" aria-label="{{ __('warehouse.map.aisle', ['aisle' => $aisle]) }} {{ __('warehouse.map.side_'.$side) }}" data-aisle="{{ $aisle }}" data-side="{{ $side }}">
                        @foreach ($rack['bays'] as $bi => $bay)
                            <text x="{{ $labelW + $bi * $cellW + $cellW / 2 }}" y="14" text-anchor="middle" font-size="11" fill="#455a64">{{ __('warehouse.map.bay', ['bay' => $bay]) }}</text>
                        @endforeach
                        @foreach ($rack['levels'] as $li => $level)
                            @php($y = $top + $li * $cellH)
                            <text x="{{ $labelW - 6 }}" y="{{ $y + $cellH / 2 + 4 }}" text-anchor="end" font-size="11" fill="#455a64">{{ __('warehouse.map.level', ['level' => $level]) }}</text>
                            @foreach ($rack['bays'] as $bi => $bay)
                                @foreach (\App\Modules\Warehouse\Models\Location::POSITIONS as $pi => $position)
                                    @php($slot = $rack['slots'][$bay][$level][$position] ?? null)
                                    @php($x = $labelW + $bi * $cellW + $pi * ($cellW / 2))
                                    @if ($slot === null)
                                        <rect x="{{ $x + 1 }}" y="{{ $y + 1 }}" width="{{ $cellW / 2 - 2 }}" height="{{ $cellH - 2 }}" fill="none" stroke="#cfd8dc" stroke-dasharray="2 2"/>
                                    @else
                                        @php($state = $slotState($slot))
                                        <a href="{{ route('warehouse.index', ['warehouse_id' => $warehouse->id, 'location' => $slot->full_code]) }}">
                                            <rect x="{{ $x + 1 }}" y="{{ $y + 1 }}" width="{{ $cellW / 2 - 2 }}" height="{{ $cellH - 2 }}" rx="2" fill="{{ MapController::fill($state) }}" stroke="{{ $slot->isBottom() ? '#f9a825' : '#607d8b' }}" stroke-width="{{ $slot->isBottom() ? 3 : 1 }}" data-code="{{ $slot->full_code }}" data-state="{{ $state }}"><title>{{ $tooltip($slot) }}</title></rect>
                                            @if ($occupancy->has($slot->id))
                                                <text x="{{ $x + $cellW / 4 }}" y="{{ $y + $cellH / 2 + 4 }}" text-anchor="middle" font-size="10" fill="#fff" pointer-events="none">{{ (int) $occupancy[$slot->id]->cartons }}</text>
                                            @endif
                                        </a>
                                    @endif
                                @endforeach
                            @endforeach
                        @endforeach
                    </svg>
                    </div>
                </div>
            @endforeach
            </div>
        @endforeach

        <h2>{{ __('warehouse.map.floor_title') }}</h2>
        @if ($floor->isEmpty())
            <p class="text-muted">{{ __('warehouse.map.floor_empty') }}</p>
        @else
            <div style="display:flex;flex-wrap:wrap;gap:.5rem" id="floor-areas">
                @foreach ($floor as $area)
                    @php($state = $slotState($area))
                    <a href="{{ route('warehouse.index', ['warehouse_id' => $warehouse->id, 'location' => $area->full_code]) }}" class="kv-card" style="text-decoration:none;margin:0;padding:.4rem .7rem;border-left:6px solid {{ MapController::fill($state) }}" data-code="{{ $area->full_code }}" data-state="{{ $state }}" title="{{ $tooltip($area) }}">
                        <code>{{ $area->full_code }}</code> <small class="text-muted">{{ __('warehouse.location_types.'.$area->type) }}</small><br>
                        <small>{{ $occupancy->has($area->id) ? __('warehouse.map.tooltip', ['units' => $occupancy[$area->id]->units, 'cartons' => (int) $occupancy[$area->id]->cartons, 'client' => $occupancy[$area->id]->client_name ?? '—']) : __('warehouse.map.states.'.$state) }}</small>
                    </a>
                @endforeach
            </div>
        @endif
    @endif
@endsection
