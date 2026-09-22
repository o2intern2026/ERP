@extends('layouts.app')

@section('title', __('warehouse.physical_containers.edit'))

@section('content')
    <p><a href="{{ route('warehouse.physical_containers.show', $box) }}">← {{ $box->container_no }}</a></p>
    <h1>{{ __('warehouse.physical_containers.edit') }} · {{ $box->container_no }}</h1>
    {{-- Audit 2026-09-22 INBOUND-16 (CR #141): every header field is editable until 登记到港 — 我方拖车 / 侧卸车 decide the charges that event raises. --}}
    <p class="text-muted"><small>{{ __('warehouse.physical_containers.edit_hint') }}</small></p>
    @error('edit')<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
    <form method="post" action="{{ route('warehouse.physical_containers.update', $box) }}">
        @csrf
        @method('PUT')
        <div class="grid">
            <label>{{ __('warehouse.physical_containers.container_no') }}<input type="text" name="container_no" class="scan" value="{{ old('container_no', $box->container_no) }}" maxlength="20" required></label>
            <label>{{ __('warehouse.physical_containers.warehouse') }}
                <select name="warehouse_id" required @disabled($box->members_count > 0)>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $box->warehouse_id) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
                @if ($box->members_count > 0)<input type="hidden" name="warehouse_id" value="{{ $box->warehouse_id }}"><small class="text-muted">{{ __('warehouse.physical_containers.errors.warehouse_change_with_members', ['no' => $box->container_no]) }}</small>@endif
            </label>
            <label>{{ __('warehouse.physical_containers.eta_date') }}<x-date-field name="eta_date" value="{{ old('eta_date', $box->eta_date?->toDateString()) }}" /></label>
        </div>
        <div class="grid">
            <label>{{ __('warehouse.physical_containers.size') }}<select name="size" required>@foreach ($sizes as $s)<option value="{{ $s }}" @selected(old('size', $box->size) === $s)>{{ __('warehouse.container_sizes.'.$s) }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.physical_containers.unpack_mode') }}<select name="unpack_mode" required>@foreach ($modes as $m)<option value="{{ $m }}" @selected(old('unpack_mode', $box->unpack_mode) === $m)>{{ __('warehouse.unpack_modes.'.$m) }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.physical_containers.gross_weight') }}<input type="number" step="0.001" min="0" name="gross_weight_kg" value="{{ old('gross_weight_kg', $box->gross_weight_kg) }}"></label>
        </div>
        <label>{{ __('warehouse.physical_containers.allocation_basis') }}
            <select name="allocation_basis" required>@foreach ($bases as $b)<option value="{{ $b }}" @selected(old('allocation_basis', $box->allocation_basis) === $b)>{{ __('warehouse.physical_containers.bases.'.$b) }}</option>@endforeach</select>
        </label>
        <div class="grid">
            <label><input type="hidden" name="cartage_by_us" value="0"><input type="checkbox" name="cartage_by_us" value="1" @checked(old('cartage_by_us', $box->cartage_by_us))> {{ __('warehouse.physical_containers.cartage_by_us') }}</label>
            <label><input type="hidden" name="sideloader_required" value="0"><input type="checkbox" name="sideloader_required" value="1" @checked(old('sideloader_required', $box->sideloader_required))> {{ __('warehouse.physical_containers.sideloader_required') }}</label>
        </div>
        <p class="text-muted"><small>{{ __('warehouse.physical_containers.cartage_hint') }}</small></p>
        <label>{{ __('warehouse.physical_containers.notes') }}<input type="text" name="notes" value="{{ old('notes', $box->notes) }}" maxlength="2000"></label>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('warehouse.physical_containers.show', $box) }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
