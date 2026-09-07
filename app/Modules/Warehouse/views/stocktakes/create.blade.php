@extends('layouts.app')

@section('title', __('warehouse.stocktakes.create'))

@section('content')
    <h1>{{ __('warehouse.stocktakes.create') }}</h1>
    <form method="post" action="{{ route('warehouse.stocktakes.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.asns.warehouse') }}<select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected(old('warehouse_id') == $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.stocktakes.scope_client') }}<select name="client_id"><option value="">—</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.stocktakes.scope_location') }}<input type="text" name="location_code" class="scan" value="{{ old('location_code') }}" placeholder="MEL-A-01-01"></label>
        </div>
        <label>{{ __('warehouse.asns.notes') }}<input type="text" name="notes" value="{{ old('notes') }}"></label>
        <button type="submit">{{ __('warehouse.stocktakes.create') }}</button>
        <a href="{{ route('warehouse.stocktakes.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
