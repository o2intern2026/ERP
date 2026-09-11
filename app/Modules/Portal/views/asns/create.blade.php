@extends('layouts.app')

@section('title', __('portal.asns.create_title'))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a></p>
    <header>
        <h1>{{ __('portal.asns.create_title') }}</h1>
        <p class="text-muted"><small>{{ __('portal.asns.create_hint') }}</small></p>
    </header>

    <form method="post" action="{{ route('portal.asns.store') }}" enctype="multipart/form-data">
        @csrf
        <article class="kv-card">
            <strong>{{ __('portal.asns.sections.header') }}</strong>
            <div class="grid">
                <label>{{ __('portal.asns.fields.warehouse') }}
                    <select name="warehouse_id" required>
                        @foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $warehouses->first()?->id) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('portal.asns.fields.inbound_type') }}
                    <select name="inbound_type" id="inbound_type" required>
                        @foreach ($inboundTypes as $t)<option value="{{ $t }}" @selected(old('inbound_type', 'container') === $t)>{{ __('warehouse.inbound_types.'.$t) }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('portal.asns.fields.expected_date') }}<input type="date" name="expected_date" required value="{{ old('expected_date') }}" min="{{ today()->toDateString() }}"></label>
                <label>{{ __('portal.asns.fields.reference') }}<input type="text" name="reference" maxlength="60" value="{{ old('reference') }}"></label>
            </div>
            <div class="grid" id="container-fields">
                <label>{{ __('portal.asns.fields.container_no') }}<input type="text" name="container_no" maxlength="20" value="{{ old('container_no') }}" placeholder="MSKU1234567" style="text-transform:uppercase"></label>
                <label>{{ __('portal.asns.fields.container_size') }}
                    <select name="container_size">@foreach ($containerSizes as $s)<option value="{{ $s }}" @selected(old('container_size', '40') === $s)>{{ __('warehouse.container_sizes.'.$s) }}</option>@endforeach</select>
                </label>
                <label>{{ __('portal.asns.fields.unpack_mode') }}
                    <select name="unpack_mode">@foreach ($unpackModes as $m)<option value="{{ $m }}" @selected(old('unpack_mode', 'loose') === $m)>{{ __('warehouse.unpack_modes.'.$m) }}</option>@endforeach</select>
                </label>
                <label>{{ __('portal.asns.fields.gross_weight_kg') }}<input type="number" name="gross_weight_kg" min="0" step="0.001" value="{{ old('gross_weight_kg') }}"></label>
            </div>
            <label>{{ __('portal.asns.fields.notes') }}<textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
        </article>

        <article class="kv-card">
            <strong>{{ __('portal.asns.fields.packing_list') }}</strong>
            <label>{{ __('portal.asns.fields.packing_list') }}<input type="file" name="packing_list" accept=".xlsx,.xls,.csv" required></label>
            <p class="text-muted"><small><a href="{{ route('portal.asns.template') }}">{{ __('portal.asns.template') }}</a> · {{ __('portal.asns.template_hint', ['headers' => implode('、', $templateHeaders)]) }}</small></p>
        </article>

        <button type="submit">{{ __('portal.asns.submit') }}</button>
    </form>

    <script>
        (function () {
            var type = document.getElementById('inbound_type'), box = document.getElementById('container-fields');
            function toggle() { box.hidden = type.value !== 'container'; }
            type.addEventListener('change', toggle);
            toggle();
        })();
    </script>
@endsection
