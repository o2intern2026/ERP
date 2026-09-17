@extends('layouts.app')

@section('title', __('warehouse.physical_containers.create'))

@section('content')
    <p><a href="{{ route('warehouse.physical_containers.index') }}">← {{ __('warehouse.physical_containers.title') }}</a></p>
    <h1>{{ __('warehouse.physical_containers.create') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.physical_containers.hint') }}</small></p>
    <form method="post" action="{{ route('warehouse.physical_containers.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.physical_containers.container_no') }}<input type="text" name="container_no" class="scan" value="{{ old('container_no', $prefill['container_no'] ?? '') }}" maxlength="20" required></label>
            <label>{{ __('warehouse.physical_containers.warehouse') }}<select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $prefill['warehouse_id'] ?? 0) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.physical_containers.eta_date') }}<x-date-field name="eta_date" value="{{ old('eta_date', $prefill['eta_date'] ?? '') }}" /></label>
        </div>
        <div class="grid">
            <label>{{ __('warehouse.physical_containers.size') }}<select name="size" required>@foreach ($sizes as $s)<option value="{{ $s }}" @selected(old('size', $prefill['size'] ?? '40') === $s)>{{ __('warehouse.container_sizes.'.$s) }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.physical_containers.unpack_mode') }}<select name="unpack_mode" required>@foreach ($modes as $m)<option value="{{ $m }}" @selected(old('unpack_mode', $prefill['unpack_mode'] ?? 'loose') === $m)>{{ __('warehouse.unpack_modes.'.$m) }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.physical_containers.gross_weight') }}<input type="number" step="0.001" min="0" name="gross_weight_kg" value="{{ old('gross_weight_kg', $prefill['gross_weight_kg'] ?? '') }}"></label>
        </div>
        <label>{{ __('warehouse.physical_containers.allocation_basis') }}
            <select name="allocation_basis"><option value="">{{ __('warehouse.physical_containers.basis_default') }}</option>@foreach ($bases as $b)<option value="{{ $b }}" @selected(old('allocation_basis') === $b)>{{ __('warehouse.physical_containers.bases.'.$b) }}</option>@endforeach</select>
        </label>
        <p class="text-muted"><small>{{ __('warehouse.physical_containers.basis_hint') }}</small></p>
        <div class="grid">
            <label><input type="checkbox" name="cartage_by_us" value="1" @checked(old('cartage_by_us'))> {{ __('warehouse.physical_containers.cartage_by_us') }}</label>
            <label><input type="checkbox" name="sideloader_required" value="1" @checked(old('sideloader_required'))> {{ __('warehouse.physical_containers.sideloader_required') }}</label>
        </div>
        <p class="text-muted"><small>{{ __('warehouse.physical_containers.cartage_hint') }}</small></p>
        <label>{{ __('warehouse.physical_containers.notes') }}<input type="text" name="notes" value="{{ old('notes') }}" maxlength="2000"></label>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('warehouse.physical_containers.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
