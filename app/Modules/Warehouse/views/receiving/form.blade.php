@extends('layouts.app')

@section('title', __('warehouse.receiving.title'))

@section('content')
    <p><a href="{{ route('warehouse.asns.show', $asn) }}">← {{ $asn->asn_no }}</a></p>
    <h1>{{ __('warehouse.receiving.title') }} · {{ __('warehouse.receiving.line') }} #{{ $line->id }}</h1>
    <p>{{ $asn->client->name }} · {{ $line->consignment_mark }} · <strong>{{ $line->description }}</strong> · {{ __('warehouse.asns.expected') }}: {{ $line->expected_cartons }}</p>
    <p><mark>{{ __($receipt['open'] ? 'warehouse.receiving.joins_receipt' : 'warehouse.receiving.new_receipt', ['no' => $receipt['no']]) }}</mark></p>
    <p class="text-muted"><small>{{ __('warehouse.receiving.hint') }}</small></p>
    @if ($receivingLocations->isEmpty())
        {{-- Audit 2026-09-10: a required <select> with no options blocks the submit silently — say what is missing and where to fix it. --}}
        <p><mark>{{ __('warehouse.receiving.no_receiving_location', ['code' => $asn->warehouse->code]) }}</mark> <a href="{{ route('warehouse.locations.index') }}">{{ __('warehouse.locations.title') }}</a></p>
    @else
    <form method="post" action="{{ route('warehouse.receiving.store', [$asn, $line]) }}">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.receiving.location') }}<select name="receiving_location_id" required>@foreach ($receivingLocations as $loc)<option value="{{ $loc->id }}" @selected((int) old('receiving_location_id') === $loc->id)>{{ $loc->full_code }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.receiving.received_cartons') }}<input type="number" name="received_cartons" class="scan" min="0" value="{{ old('received_cartons', $line->expected_cartons) }}" required autofocus></label>
            <label>{{ __('warehouse.receiving.damaged_cartons') }}<input type="number" name="damaged_cartons" min="0" value="{{ old('damaged_cartons', 0) }}"></label>
            @if ($asn->inbound_type === 'loose_truck')
                <label>{{ __('warehouse.receiving.unloaded_pallets') }}<input type="number" name="unloaded_pallets" min="0" value="{{ old('unloaded_pallets') }}"></label>
            @endif
        </div>
        <label>{{ __('warehouse.receiving.variance_reason') }}<input type="text" name="variance_reason" value="{{ old('variance_reason') }}"></label>
        <fieldset>
            <legend>{{ __('warehouse.receiving.units_title') }}</legend>
            <div id="units">
                @for ($i = 0; $i < 3; $i++)
                    {{-- Rows 2–3 start hidden AND disabled: disabled controls are not submitted, so they cannot trip the units.*.carton_qty rule (tester feedback 2026-09-10). --}}
                    <div class="grid unit-row" @if ($i > 0) hidden @endif>
                        <select name="units[{{ $i }}][unit_type]" @disabled($i > 0)>@foreach (\App\Support\Enums::UNIT_TYPES as $t)<option value="{{ $t }}" @selected(old("units.$i.unit_type", 'pallet') === $t)>{{ __('warehouse.unit_types.'.$t) }}</option>@endforeach</select>
                        <input type="number" name="units[{{ $i }}][carton_qty]" @disabled($i > 0) min="0" placeholder="{{ __('warehouse.receiving.carton_qty') }}" value="{{ old("units.$i.carton_qty", $i === 0 ? $line->expected_cartons : '') }}">
                        <input type="number" name="units[{{ $i }}][length_mm]" @disabled($i > 0) min="1" placeholder="{{ __('warehouse.receiving.length') }}" value="{{ old("units.$i.length_mm") }}">
                        <input type="number" name="units[{{ $i }}][width_mm]" @disabled($i > 0) min="1" placeholder="{{ __('warehouse.receiving.width') }}" value="{{ old("units.$i.width_mm") }}">
                        <input type="number" name="units[{{ $i }}][height_mm]" @disabled($i > 0) min="1" placeholder="{{ __('warehouse.receiving.height') }}" value="{{ old("units.$i.height_mm") }}">
                        <input type="number" step="0.001" name="units[{{ $i }}][weight_kg]" @disabled($i > 0) min="0" placeholder="{{ __('warehouse.receiving.weight') }}" value="{{ old("units.$i.weight_kg") }}">
                        <select name="units[{{ $i }}][pallet_source]" @disabled($i > 0)><option value="">{{ __('warehouse.receiving.pallet_source') }}</option>@foreach ($palletSources as $s)<option value="{{ $s }}" @selected(old("units.$i.pallet_source") === $s)>{{ __('warehouse.pallet_sources.'.$s) }}</option>@endforeach</select>
                        <select name="units[{{ $i }}][pallet_class]" @disabled($i > 0)><option value="">{{ __('warehouse.receiving.pallet_class') }}</option>@foreach ($palletClasses as $c)<option value="{{ $c }}" @selected(old("units.$i.pallet_class") === $c)>{{ __('warehouse.pallet_classes.'.$c) }}</option>@endforeach</select>
                    </div>
                @endfor
            </div>
            <button type="button" class="secondary outline" id="add-unit">{{ __('warehouse.receiving.add_unit') }}</button>
        </fieldset>
        <button type="submit">{{ __('warehouse.receiving.submit') }}</button>
    </form>
    @endif
@endsection

@push('scripts')
<script>
    document.getElementById('add-unit')?.addEventListener('click', () => {
        const hiddenRow = document.querySelector('#units .unit-row[hidden]');
        if (!hiddenRow) return;
        hiddenRow.hidden = false;
        hiddenRow.querySelectorAll('input, select').forEach(el => { el.disabled = false; });
    });
</script>
@endpush
