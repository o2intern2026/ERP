@extends('layouts.app')

@section('title', __('warehouse.locations.title'))

@section('content')
    <h1>{{ __('warehouse.locations.title') }} <small><a href="{{ route('warehouse.map.index') }}">{{ __('warehouse.map.link') }}</a></small></h1>
    <p class="text-muted"><small>{{ __('warehouse.locations.hint') }}</small></p>
    {{-- CHANGE_REQUESTS #164: the six-part code explained once, above every form. --}}
    <p class="text-muted"><small>{{ __('warehouse.locations.code_hint') }}</small></p>
    {{-- Audit 2026-09-10: both forms keep what was typed after a validation error (old()). --}}
    @role('admin|warehouse_supervisor')
        <form method="post" action="{{ route('warehouse.warehouses.store') }}" class="grid">
            @csrf
            <input type="text" name="code" placeholder="{{ __('warehouse.warehouses.code') }}" maxlength="10" value="{{ old('code') }}" required>
            <input type="text" name="name" placeholder="{{ __('warehouse.warehouses.name') }}" value="{{ old('name') }}" required>
            <input type="text" name="address" placeholder="{{ __('warehouse.warehouses.address') }}" value="{{ old('address') }}">
            <input type="text" name="suburb" placeholder="{{ __('warehouse.warehouses.suburb') }}" value="{{ old('suburb') }}">
            <input type="text" name="state" placeholder="{{ __('warehouse.warehouses.state') }}" maxlength="3" value="{{ old('state') }}">
            <input type="text" name="postcode" placeholder="{{ __('warehouse.warehouses.postcode') }}" maxlength="10" value="{{ old('postcode') }}">
            <button type="submit" class="secondary outline">{{ __('warehouse.warehouses.create') }}</button>
        </form>
    @endrole
    @role('admin|warehouse_supervisor|warehouse_operator')
        <form method="post" action="{{ route('warehouse.locations.store') }}" class="grid">
            @csrf
            <select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $warehouses->first()?->id) === $w->id)>{{ $w->code }}</option>@endforeach</select>
            <input type="text" name="zone" placeholder="{{ __('warehouse.locations.zone') }}" maxlength="10" value="{{ old('zone') }}" required>
            <input type="text" name="aisle" placeholder="{{ __('warehouse.locations.aisle') }}" maxlength="10" value="{{ old('aisle') }}" required>
            <input type="text" name="bay" placeholder="{{ __('warehouse.locations.bay') }}" maxlength="10" value="{{ old('bay') }}" required>
            <select name="type">@foreach ($types as $t)<option value="{{ $t }}" @selected(old('type', 'storage') === $t)>{{ __('warehouse.location_types.'.$t) }}</option>@endforeach</select>
            <input type="number" name="rack_level" min="1" max="99" placeholder="{{ __('warehouse.locations.rack_level') }}" value="{{ old('rack_level') }}">
            <select name="position" aria-label="{{ __('warehouse.locations.position') }}"><option value="">{{ __('warehouse.locations.position_none') }}</option>@foreach ($positions as $p)<option value="{{ $p }}" @selected((string) old('position') === (string) $p)>{{ __('warehouse.locations.position_option', ['position' => $p, 'side' => __('warehouse.locations.sides.'.($p === 1 ? 'left' : 'right'))]) }}</option>@endforeach</select>
            <select name="storage_tier" aria-label="{{ __('warehouse.locations.storage_tier') }}">@foreach ($tiers as $tier)<option value="{{ $tier }}" @selected(old('storage_tier', 'standard') === $tier)>{{ __('warehouse.storage_tiers.'.$tier) }}</option>@endforeach</select>
            <button type="submit" class="secondary">{{ __('warehouse.locations.create') }}</button>
        </form>
        <p class="text-muted"><small>{{ __('warehouse.locations.tier_hint') }}</small></p>
    @endrole
    @role('admin|warehouse_supervisor')
        {{-- CHANGE_REQUESTS #164: 批量生成 — a rack grid in one post; existing codes are skipped. --}}
        <details{{ $errors->hasAny(['aisle_to', 'bay_to', 'levels', 'positions', 'bottom_level_one']) ? ' open' : '' }}>
            <summary role="button" class="secondary outline">{{ __('warehouse.locations.generate.title') }}</summary>
            <form method="post" action="{{ route('warehouse.locations.generate') }}" id="generate-locations">
                @csrf
                <p class="text-muted"><small>{{ __('warehouse.locations.generate.hint') }}</small></p>
                <div class="grid">
                    <select name="warehouse_id" required aria-label="{{ __('warehouse.locations.warehouse') }}">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $warehouses->first()?->id) === $w->id)>{{ $w->code }}</option>@endforeach</select>
                    <input type="text" name="zone" maxlength="10" placeholder="{{ __('warehouse.locations.zone') }}" value="{{ old('zone') }}" required>
                    <input type="number" name="aisle_from" min="1" max="99" placeholder="{{ __('warehouse.locations.generate.aisle_from') }}" value="{{ old('aisle_from', 1) }}" required>
                    <input type="number" name="aisle_to" min="1" max="99" placeholder="{{ __('warehouse.locations.generate.aisle_to') }}" value="{{ old('aisle_to') }}" required>
                    <input type="number" name="bay_from" min="1" max="99" placeholder="{{ __('warehouse.locations.generate.bay_from') }}" value="{{ old('bay_from', 1) }}" required>
                    <input type="number" name="bay_to" min="1" max="99" placeholder="{{ __('warehouse.locations.generate.bay_to') }}" value="{{ old('bay_to') }}" required>
                </div>
                <div class="grid">
                    <label>{{ __('warehouse.locations.generate.levels') }}<input type="number" name="levels" min="1" max="9" value="{{ old('levels', 3) }}" required></label>
                    <label>{{ __('warehouse.locations.generate.positions') }}<select name="positions">@foreach ($positions as $p)<option value="{{ $p }}" @selected((int) old('positions', 2) === $p)>{{ $p }}</option>@endforeach</select></label>
                    <label>{{ __('warehouse.locations.type') }}<select name="type">@foreach (['storage', 'pickface'] as $t)<option value="{{ $t }}" @selected(old('type', 'storage') === $t)>{{ __('warehouse.location_types.'.$t) }}</option>@endforeach</select></label>
                    <label><input type="checkbox" name="bottom_level_one" value="1" @checked(old('bottom_level_one'))> {{ __('warehouse.locations.generate.bottom_level_one') }}</label>
                    <label>&nbsp;<button type="submit" class="secondary">{{ __('warehouse.locations.generate.submit') }}</button></label>
                </div>
            </form>
        </details>
        {{-- CHANGE_REQUESTS #126: 批量设置 — storage locations only; every change is written to the audit log. --}}
        <details{{ $errors->hasAny(['set_rack_level', 'set_storage_tier', 'aisle_from', 'bay_from']) ? ' open' : '' }}>
            <summary role="button" class="secondary outline">{{ __('warehouse.locations.bulk.title') }}</summary>
            <form method="post" action="{{ route('warehouse.locations.bulk') }}">
                @csrf
                <p class="text-muted"><small>{{ __('warehouse.locations.bulk.hint') }}</small></p>
                <div class="grid">
                    <select name="warehouse_id" required aria-label="{{ __('warehouse.locations.warehouse') }}">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $warehouses->first()?->id) === $w->id)>{{ $w->code }}</option>@endforeach</select>
                    <input type="text" name="zone" maxlength="10" placeholder="{{ __('warehouse.locations.zone') }}" value="{{ old('zone') }}">
                    <input type="text" name="aisle_from" maxlength="10" placeholder="{{ __('warehouse.locations.bulk.aisle_from') }}" value="{{ old('aisle_from') }}">
                    <input type="text" name="aisle_to" maxlength="10" placeholder="{{ __('warehouse.locations.bulk.aisle_to') }}" value="{{ old('aisle_to') }}">
                    <input type="text" name="bay_from" maxlength="10" placeholder="{{ __('warehouse.locations.bulk.bay_from') }}" value="{{ old('bay_from') }}">
                    <input type="text" name="bay_to" maxlength="10" placeholder="{{ __('warehouse.locations.bulk.bay_to') }}" value="{{ old('bay_to') }}">
                </div>
                <div class="grid">
                    <input type="number" name="set_rack_level" min="1" max="99" placeholder="{{ __('warehouse.locations.bulk.set_rack_level') }}" value="{{ old('set_rack_level') }}">
                    <select name="set_storage_tier" aria-label="{{ __('warehouse.locations.bulk.set_storage_tier') }}"><option value="">{{ __('warehouse.locations.bulk.keep_tier') }}</option>@foreach ($tiers as $tier)<option value="{{ $tier }}" @selected(old('set_storage_tier') === $tier)>{{ __('warehouse.storage_tiers.'.$tier) }}</option>@endforeach</select>
                    <button type="submit" class="secondary">{{ __('warehouse.locations.bulk.submit') }}</button>
                </div>
            </form>
        </details>
    @endrole
    @foreach ($warehouses as $w)
        <h2>{{ $w->code }} · {{ $w->name }} <small><a href="{{ route('warehouse.labels.locations', ['warehouse_id' => $w->id]) }}" target="_blank">{{ __('warehouse.labels.locations') }}</a></small></h2>
        {{-- Audit 2026-09-22 CRAWL-01 (CR #141): print a zone / aisle range / type instead of every location; 40+ labels come as numbered batches. --}}
        <details class="label-filter">
            <summary>{{ __('warehouse.labels.print_filter') }}</summary>
            <form method="get" action="{{ route('warehouse.labels.locations') }}" target="_blank" class="grid">
                <input type="hidden" name="warehouse_id" value="{{ $w->id }}">
                <label>{{ __('warehouse.labels.zone') }}<select name="zone"><option value="">{{ __('platform.jobs.all') }}</option>@foreach ($w->locations->pluck('zone')->unique()->sort()->values() as $zone)<option value="{{ $zone }}">{{ $zone }}</option>@endforeach</select></label>
                <label>{{ __('warehouse.labels.aisle_from') }}<input type="text" name="aisle_from" maxlength="10" placeholder="01"></label>
                <label>{{ __('warehouse.labels.aisle_to') }}<input type="text" name="aisle_to" maxlength="10" placeholder="05"></label>
                <label>{{ __('warehouse.locations.type') }}<select name="type"><option value="">{{ __('platform.jobs.all') }}</option>@foreach (\App\Support\Enums::LOCATION_TYPES as $type)<option value="{{ $type }}">{{ __('warehouse.location_types.'.$type) }}</option>@endforeach</select></label>
                <label>&nbsp;<button type="submit" class="secondary">{{ __('warehouse.labels.print') }}</button></label>
            </form>
            <p class="text-muted"><small>{{ __('warehouse.labels.print_filter_hint', ['size' => \App\Modules\Warehouse\Services\LabelService::BATCH_SIZE]) }}</small></p>
        </details>
        @if ($w->locations->where('type', 'receiving')->where('active', true)->isEmpty())
            <p><mark>{{ __('warehouse.locations.no_receiving', ['code' => $w->code]) }}</mark></p>
        @endif
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.locations.full_code') }}</th><th>{{ __('warehouse.locations.zone') }}</th><th>{{ __('warehouse.locations.aisle') }}</th><th>{{ __('warehouse.locations.bay') }}</th><th>{{ __('warehouse.locations.position') }}</th><th>{{ __('warehouse.locations.type') }}</th><th class="num">{{ __('warehouse.locations.rack_level') }}</th><th>{{ __('warehouse.locations.storage_tier') }}</th><th>{{ __('warehouse.locations.active') }}</th></tr></thead>
            <tbody>@foreach ($w->locations as $l)<tr><td><code>{{ $l->full_code }}</code></td><td>{{ $l->zone }}</td><td>{{ $l->aisle }}</td><td>{{ $l->bay }}@if ($l->side()) <small class="text-muted">{{ __('warehouse.locations.sides.'.$l->side()) }}</small>@endif</td><td>{{ $l->position === null ? '—' : __('warehouse.locations.position_option', ['position' => $l->position, 'side' => __('warehouse.locations.sides.'.($l->position === 1 ? 'left' : 'right'))]) }}</td><td>{{ __('warehouse.location_types.'.$l->type) }}</td><td class="num">{{ $l->rack_level ?? '—' }}</td><td>@if ($l->type === 'storage')<span class="badge" data-tone="{{ $l->storage_tier === 'bottom' ? 'warn' : 'muted' }}">{{ __('warehouse.storage_tiers.'.$l->storage_tier) }}</span>@else — @endif</td><td>{{ $l->active ? __('platform.common.yes') : __('platform.common.no') }}</td></tr>@endforeach</tbody>
        </table></div>
    @endforeach
@endsection
