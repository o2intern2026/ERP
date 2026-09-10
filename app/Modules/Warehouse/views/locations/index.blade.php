@extends('layouts.app')

@section('title', __('warehouse.locations.title'))

@section('content')
    <h1>{{ __('warehouse.locations.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.locations.hint') }}</small></p>
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
            <input type="text" name="bin" placeholder="{{ __('warehouse.locations.bin') }}" maxlength="10" value="{{ old('bin') }}" required>
            <select name="type">@foreach ($types as $t)<option value="{{ $t }}" @selected(old('type', 'storage') === $t)>{{ __('warehouse.location_types.'.$t) }}</option>@endforeach</select>
            <button type="submit" class="secondary">{{ __('warehouse.locations.create') }}</button>
        </form>
    @endrole
    @foreach ($warehouses as $w)
        <h2>{{ $w->code }} · {{ $w->name }} <small><a href="{{ route('warehouse.labels.locations', ['warehouse_id' => $w->id]) }}" target="_blank">{{ __('warehouse.labels.locations') }}</a></small></h2>
        @if ($w->locations->where('type', 'receiving')->where('active', true)->isEmpty())
            <p><mark>{{ __('warehouse.locations.no_receiving', ['code' => $w->code]) }}</mark></p>
        @endif
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.locations.full_code') }}</th><th>{{ __('warehouse.locations.zone') }}</th><th>{{ __('warehouse.locations.aisle') }}</th><th>{{ __('warehouse.locations.bin') }}</th><th>{{ __('warehouse.locations.type') }}</th><th>{{ __('warehouse.locations.active') }}</th></tr></thead>
            <tbody>@foreach ($w->locations as $l)<tr><td><code>{{ $l->full_code }}</code></td><td>{{ $l->zone }}</td><td>{{ $l->aisle }}</td><td>{{ $l->bin }}</td><td>{{ __('warehouse.location_types.'.$l->type) }}</td><td>{{ $l->active ? __('platform.common.yes') : __('platform.common.no') }}</td></tr>@endforeach</tbody>
        </table></div>
    @endforeach
@endsection
