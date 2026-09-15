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
@endsection
