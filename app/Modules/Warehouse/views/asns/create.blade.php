@extends('layouts.app')

@section('title', __('warehouse.asns.create'))

@section('content')
    <h1>{{ __('warehouse.asns.create') }}</h1>
    <form method="post" action="{{ route('warehouse.asns.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.asns.client') }}<select name="client_id" required><option value="">—</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->code }} · {{ $c->name }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.asns.warehouse') }}<select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected(old('warehouse_id') == $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.asns.inbound_type') }}<select name="inbound_type" id="inbound_type" required>@foreach (\App\Support\Enums::INBOUND_TYPES as $t)<option value="{{ $t }}" @selected(old('inbound_type', 'container') === $t)>{{ __('warehouse.inbound_types.'.$t) }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.asns.expected_date') }}<input type="date" name="expected_date" value="{{ old('expected_date') }}"></label>
        </div>
        <div class="grid">
            <label>{{ __('warehouse.asns.existing_job') }}<select name="job_id"><option value="">{{ __('warehouse.asns.new_job') }}</option>@foreach ($jobs as $j)<option value="{{ $j->id }}" @selected(old('job_id') == $j->id)>{{ $j->job_no }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.asns.reference') }}<input type="text" name="reference" value="{{ old('reference') }}"></label>
        </div>
        <label><input type="hidden" name="unplanned" value="0"><input type="checkbox" name="unplanned" value="1" @checked(old('unplanned'))> {{ __('warehouse.asns.unplanned') }}</label>
        <fieldset id="containers">
            <legend>{{ __('warehouse.asns.containers') }}</legend>
            @for ($i = 0; $i < 2; $i++)
                <div class="grid container-row">
                    <input type="text" name="containers[{{ $i }}][container_no]" placeholder="{{ __('warehouse.asns.container_no') }}" value="{{ old("containers.$i.container_no") }}" maxlength="20">
                    <select name="containers[{{ $i }}][size]">@foreach (\App\Support\Enums::CONTAINER_SIZES as $s)<option value="{{ $s }}" @selected(old("containers.$i.size", '40') === $s)>{{ __('warehouse.container_sizes.'.$s) }}</option>@endforeach</select>
                    <select name="containers[{{ $i }}][unpack_mode]">@foreach (\App\Support\Enums::UNPACK_MODES as $m)<option value="{{ $m }}" @selected(old("containers.$i.unpack_mode", 'loose') === $m)>{{ __('warehouse.unpack_modes.'.$m) }}</option>@endforeach</select>
                    <input type="number" step="0.001" min="0" name="containers[{{ $i }}][gross_weight_kg]" placeholder="{{ __('warehouse.asns.gross_weight') }}" value="{{ old("containers.$i.gross_weight_kg") }}">
                </div>
            @endfor
        </fieldset>
        <label>{{ __('warehouse.asns.notes') }}<textarea name="notes" rows="2">{{ old('notes') }}</textarea></label>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('warehouse.asns.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection

@push('scripts')
<script>
    const typeSelect = document.getElementById('inbound_type'), containers = document.getElementById('containers');
    const toggle = () => { containers.hidden = typeSelect.value !== 'container'; };
    typeSelect.addEventListener('change', toggle); toggle();
</script>
@endpush
