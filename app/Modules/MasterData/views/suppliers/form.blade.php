@extends('layouts.app')

@section('title', $supplier->exists ? __('masterdata.suppliers.edit') : __('masterdata.suppliers.create'))

@section('content')
    <h1>{{ $supplier->exists ? __('masterdata.suppliers.edit') : __('masterdata.suppliers.create') }}</h1>
    <form method="post" action="{{ $supplier->exists ? route('masterdata.suppliers.update', $supplier) : route('masterdata.suppliers.store') }}">
        @csrf
        @if ($supplier->exists) @method('PUT') @endif
        <div class="grid">
            <label>{{ __('masterdata.fields.code') }}<input type="text" name="code" value="{{ old('code', $supplier->code) }}" maxlength="20" required></label>
            <label>{{ __('masterdata.fields.name') }}<input type="text" name="name" value="{{ old('name', $supplier->name) }}" required></label>
            <label>{{ __('masterdata.fields.abn') }}<input type="text" name="abn" value="{{ old('abn', $supplier->abn) }}" maxlength="20"></label>
        </div>
        @include('masterdata::partials.contact-fields', ['model' => $supplier])
        <div class="grid">
            <label>{{ __('masterdata.fields.address') }}<input type="text" name="address" value="{{ old('address', $supplier->address) }}"></label>
            <label>{{ __('masterdata.fields.status') }}
                <select name="status">
                    @foreach (\App\Support\Enums::MASTER_STATUSES as $v)
                        <option value="{{ $v }}" @selected(old('status', $supplier->status ?? 'active') === $v)>{{ __('masterdata.statuses.'.$v) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('masterdata.suppliers.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
