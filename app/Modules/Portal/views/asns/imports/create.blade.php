@extends('layouts.app')

@section('title', __('portal.inbound.title'))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a> · <a href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.list_title') }}</a></p>
    <header>
        <h1>{{ __('portal.inbound.title') }}</h1>
        <p class="text-muted"><small>{{ __('portal.inbound.hint') }}</small></p>
    </header>

    @if ($errors->any())
        <article><strong>{{ __('portal.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <form method="post" action="{{ route('portal.asns.imports.store') }}" enctype="multipart/form-data">
        @csrf
        <article class="kv-card">
            <strong>{{ __('portal.inbound.sections.context') }}</strong>
            <p class="text-muted"><small>{{ __('portal.inbound.context_hint') }}</small></p>
            <div class="grid">
                <label>{{ __('portal.inbound.fields.container_no') }}<input type="text" name="container_no" maxlength="20" value="{{ old('container_no') }}" placeholder="MSKU1234567" style="text-transform:uppercase"></label>
                <label>{{ __('portal.inbound.fields.container_size') }}
                    <select name="container_size">
                        <option value="">{{ __('portal.actions.select') }}</option>
                        @foreach ($containerSizes as $size)<option value="{{ $size }}" @selected(old('container_size') === $size)>{{ __('warehouse.container_sizes.'.$size) }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('portal.inbound.fields.expected_date') }}<input type="date" name="expected_date" value="{{ old('expected_date') }}"></label>
                <label>{{ __('portal.inbound.fields.reference') }}<input type="text" name="reference" maxlength="60" value="{{ old('reference') }}"></label>
            </div>
            <label>{{ __('portal.inbound.fields.notes') }}<textarea name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea></label>
        </article>

        {{-- CHANGE_REQUESTS #125: 到仓方式. The pickup fieldset is hidden AND disabled unless 需要你们上门提货 is ticked, so nothing of it submits otherwise. --}}
        @php($weCollect = old('inbound_transport') === 'we_collect')
        <article class="kv-card" id="inbound-transport">
            <strong>{{ __('portal.inbound.collection.section') }}</strong>
            <p style="margin:.3rem 0">
                @foreach (\App\Support\Enums::ASN_INBOUND_TRANSPORTS as $mode)
                    <label style="display:inline-block;margin-right:1.2rem"><input type="radio" name="inbound_transport" value="{{ $mode }}" @checked(($weCollect ? 'we_collect' : 'client_delivers') === $mode)> {{ __('portal.inbound.collection.modes.'.$mode) }}</label>
                @endforeach
            </p>
            <fieldset id="collection-fields" {{ $weCollect ? '' : 'hidden disabled' }}>
                <p class="text-muted" style="margin:.2rem 0"><small>{{ __('portal.inbound.collection.hint') }}</small></p>
                @if ($warehouses->count() === 1)
                    <input type="hidden" name="warehouse_id" value="{{ $warehouses->first()->id }}">
                @else
                    <label>{{ __('portal.inbound.collection.fields.warehouse') }}
                        <select name="warehouse_id">@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $defaultWarehouseId) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
                    </label>
                @endif
                <div class="grid">
                    <label>{{ __('portal.inbound.collection.fields.name') }}<input type="text" name="collection[name]" maxlength="255" value="{{ old('collection.name') }}"></label>
                    <label>{{ __('portal.inbound.collection.fields.phone') }}<input type="text" name="collection[phone]" maxlength="40" value="{{ old('collection.phone') }}"></label>
                    <label>{{ __('portal.inbound.collection.fields.type') }}<select name="collection[type]">@foreach (\App\Support\Enums::ADDRESS_TYPES as $t)<option value="{{ $t }}" @selected(old('collection.type', 'business') === $t)>{{ __('portal.inbound.collection.address_types.'.$t) }}</option>@endforeach</select></label>
                </div>
                <div class="grid">
                    <label>{{ __('portal.inbound.collection.fields.address') }}<input type="text" name="collection[address]" maxlength="255" value="{{ old('collection.address') }}"></label>
                    <label>{{ __('portal.inbound.collection.fields.suburb') }}<input type="text" name="collection[suburb]" maxlength="100" value="{{ old('collection.suburb') }}"></label>
                    <label>{{ __('portal.inbound.collection.fields.state') }}<select name="collection[state]"><option value="">{{ __('portal.actions.select') }}</option>@foreach (\App\Support\Enums::STATES as $state)<option value="{{ $state }}" @selected(old('collection.state') === $state)>{{ $state }}</option>@endforeach</select></label>
                    <label>{{ __('portal.inbound.collection.fields.postcode') }}<input type="text" name="collection[postcode]" maxlength="4" inputmode="numeric" value="{{ old('collection.postcode') }}"></label>
                </div>
                <div class="grid">
                    <label>{{ __('portal.inbound.collection.fields.ready_date') }}<input type="date" name="collection_ready_date" min="{{ today()->toDateString() }}" value="{{ old('collection_ready_date') }}"></label>
                    <label>{{ __('portal.inbound.collection.fields.notes') }}<input type="text" name="collection_notes" maxlength="2000" value="{{ old('collection_notes') }}"></label>
                </div>
            </fieldset>
        </article>

        <article class="kv-card">
            <strong>{{ __('portal.inbound.sections.file') }}</strong>
            <label>{{ __('portal.inbound.fields.file') }}<input type="file" name="manifest" accept=".csv,.xlsx,.xls" required></label>
            <p><small><a href="{{ route('portal.asns.imports.template') }}">{{ __('portal.inbound.template') }}</a> · {{ __('portal.inbound.template_hint') }}</small></p>
            <p class="text-muted"><small>{{ implode(' · ', $templateHeaders) }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.defaults_hint') }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.storage_tier_hint') }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.pitfalls') }}</small></p>
        </article>

        <button type="submit">{{ __('portal.inbound.actions.upload') }}</button>
        <a class="secondary" role="button" href="{{ route('portal.asns.index') }}">{{ __('portal.inbound.actions.back') }}</a>
    </form>
    <script>
        (function () {
            var modes = Array.prototype.slice.call(document.querySelectorAll('input[name="inbound_transport"]'));
            var box = document.getElementById('collection-fields');
            function toggle() {
                var weCollect = modes.some(function (r) { return r.checked && r.value === 'we_collect'; });
                box.hidden = !weCollect;
                box.disabled = !weCollect;
            }
            modes.forEach(function (r) { r.addEventListener('change', toggle); });
            toggle();
        })();
    </script>
@endsection
