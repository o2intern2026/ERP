@extends('layouts.app')

@section('title', __('warehouse.locations.title'))

@section('content')
    <h1>{{ __('warehouse.locations.title') }}</h1>
    @role('admin|warehouse_supervisor|warehouse_operator')
        <form method="post" action="{{ route('warehouse.locations.store') }}" class="grid">
            @csrf
            <select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}">{{ $w->code }}</option>@endforeach</select>
            <input type="text" name="zone" placeholder="{{ __('warehouse.locations.zone') }}" maxlength="10" required>
            <input type="text" name="aisle" placeholder="{{ __('warehouse.locations.aisle') }}" maxlength="10" required>
            <input type="text" name="bin" placeholder="{{ __('warehouse.locations.bin') }}" maxlength="10" required>
            <select name="type">@foreach ($types as $t)<option value="{{ $t }}" @selected($t === 'storage')>{{ __('warehouse.location_types.'.$t) }}</option>@endforeach</select>
            <button type="submit" class="secondary">{{ __('warehouse.locations.create') }}</button>
        </form>
    @endrole
    @foreach ($warehouses as $w)
        <h2>{{ $w->code }} · {{ $w->name }}</h2>
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.locations.full_code') }}</th><th>{{ __('warehouse.locations.zone') }}</th><th>{{ __('warehouse.locations.aisle') }}</th><th>{{ __('warehouse.locations.bin') }}</th><th>{{ __('warehouse.locations.type') }}</th><th>{{ __('warehouse.locations.active') }}</th></tr></thead>
            <tbody>@foreach ($w->locations as $l)<tr><td><code>{{ $l->full_code }}</code></td><td>{{ $l->zone }}</td><td>{{ $l->aisle }}</td><td>{{ $l->bin }}</td><td>{{ __('warehouse.location_types.'.$l->type) }}</td><td>{{ $l->active ? __('platform.common.yes') : __('platform.common.no') }}</td></tr>@endforeach</tbody>
        </table></div>
    @endforeach
@endsection
