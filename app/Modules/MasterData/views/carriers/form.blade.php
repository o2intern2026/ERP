@extends('layouts.app')

@section('title', $carrier->exists ? __('masterdata.carriers.edit') : __('masterdata.carriers.create'))

@section('content')
    <h1>{{ $carrier->exists ? __('masterdata.carriers.edit') : __('masterdata.carriers.create') }}</h1>
    <form method="post" action="{{ $carrier->exists ? route('masterdata.carriers.update', $carrier) : route('masterdata.carriers.store') }}">
        @csrf
        @if ($carrier->exists) @method('PUT') @endif
        <div class="grid">
            <label>{{ __('masterdata.fields.code') }}<input type="text" name="code" value="{{ old('code', $carrier->code) }}" maxlength="20" required></label>
            <label>{{ __('masterdata.fields.name') }}<input type="text" name="name" value="{{ old('name', $carrier->name) }}" required></label>
            <label>{{ __('masterdata.fields.abn') }}<input type="text" name="abn" value="{{ old('abn', $carrier->abn) }}" maxlength="20"></label>
        </div>
        @include('masterdata::partials.contact-fields', ['model' => $carrier])
        <div class="grid">

            <label>{{ __('masterdata.fields.status') }}
                <select name="status">
                    @foreach (\App\Support\Enums::MASTER_STATUSES as $v)
                        <option value="{{ $v }}" @selected(old('status', $carrier->status ?? 'active') === $v)>{{ __('masterdata.statuses.'.$v) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('masterdata.carriers.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
