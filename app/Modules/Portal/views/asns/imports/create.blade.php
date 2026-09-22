@extends('layouts.app')

@section('title', __('portal.inbound.title'))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a> · <a href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.list_title') }}</a> · <a href="{{ route('portal.asns.imports.manual.create') }}">{{ __('portal.inbound.manual.button') }}</a></p>
    <header>
        <h1>{{ __('portal.inbound.title') }}</h1>
        <p class="text-muted"><small>{{ __('portal.inbound.hint') }}</small></p>
    </header>

    @if ($errors->any())
        <article><strong>{{ __('portal.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <form method="post" action="{{ route('portal.asns.imports.store') }}" enctype="multipart/form-data">
        @csrf
        @include('portal::asns.imports.partials.context-fields')
        {{-- CHANGE_REQUESTS #143 导入选项 (分组规则 / 地址类型默认) — shared with 手工建立入库清单. --}}
        @include('portal::asns.imports.partials.import-options')
        {{-- CHANGE_REQUESTS #125 到仓方式 — the fieldset and its toggle live in the partial shared with 手工建立入库清单 (#128). --}}
        @include('portal::asns.imports.partials.collection-fields')

        <article class="kv-card">
            <strong>{{ __('portal.inbound.sections.file') }}</strong>
            <label>{{ __('portal.inbound.fields.file') }}<input type="file" name="manifest" accept=".csv,.xlsx,.xls" required></label>
            <p><small><a href="{{ route('portal.asns.imports.template') }}">{{ __('portal.inbound.template') }}</a> · {{ __('portal.inbound.template_hint') }}</small></p>
            <p class="text-muted"><small>{{ implode(' · ', $templateHeaders) }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.consolidation_hint') }}</small></p>{{-- CHANGE_REQUESTS #143: the English consolidation list uploads as is --}}
            <p class="text-muted"><small>{{ __('portal.inbound.defaults_hint') }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.storage_tier_hint') }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.address_type_hint') }}</small></p>{{-- CHANGE_REQUESTS #136 --}}
            <p class="text-muted"><small>{{ __('portal.inbound.pitfalls') }}</small></p>
        </article>

        <button type="submit">{{ __('portal.inbound.actions.upload') }}</button>
        <a class="secondary" role="button" href="{{ route('portal.asns.index') }}">{{ __('portal.inbound.actions.back') }}</a>
    </form>
@endsection
