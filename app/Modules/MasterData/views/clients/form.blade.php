@extends('layouts.app')

@section('title', $client->exists ? __('masterdata.clients.edit') : __('masterdata.clients.create'))

@section('content')
    <h1>{{ $client->exists ? __('masterdata.clients.edit') : __('masterdata.clients.create') }}</h1>
    <form method="post" action="{{ $client->exists ? route('masterdata.clients.update', $client) : route('masterdata.clients.store') }}">
        @csrf
        @if ($client->exists) @method('PUT') @endif
        <div class="grid">
            <label>{{ __('masterdata.fields.code') }}<input type="text" name="code" value="{{ old('code', $client->code) }}" maxlength="20" required></label>
            <label>{{ __('masterdata.fields.name') }}<input type="text" name="name" value="{{ old('name', $client->name) }}" required></label>
            <label>{{ __('masterdata.fields.abn') }}<input type="text" name="abn" value="{{ old('abn', $client->abn) }}" maxlength="20"></label>
        </div>
        <div class="grid">
            <label>{{ __('masterdata.fields.leg_type') }}
                <select name="leg_type">
                    @foreach (\App\Support\Enums::LEG_TYPES as $v)
                        <option value="{{ $v }}" @selected(old('leg_type', $client->leg_type ?? 'both') === $v)>{{ __('masterdata.leg_types.'.$v) }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('masterdata.fields.status') }}
                <select name="status">
                    @foreach (\App\Support\Enums::MASTER_STATUSES as $v)
                        <option value="{{ $v }}" @selected(old('status', $client->status ?? 'active') === $v)>{{ __('masterdata.statuses.'.$v) }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('masterdata.fields.billing_email') }}<input type="email" name="billing_email" value="{{ old('billing_email', $client->billing_email) }}"></label>
        </div>
        @include('masterdata::partials.contact-fields', ['model' => $client])
        <div class="grid">
            <label>{{ __('masterdata.fields.address') }}<input type="text" name="address" value="{{ old('address', $client->address) }}"></label>
            <label>{{ __('masterdata.fields.suburb') }}<input type="text" name="suburb" value="{{ old('suburb', $client->suburb) }}"></label>
            <label>{{ __('masterdata.fields.state') }}
                <select name="state">
                    <option value="">—</option>
                    @foreach ($states as $s)
                        <option value="{{ $s }}" @selected(old('state', $client->state) === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('masterdata.fields.postcode') }}<input type="text" name="postcode" value="{{ old('postcode', $client->postcode) }}" maxlength="10"></label>
        </div>
        <fieldset>
            <legend>{{ __('masterdata.clients.billing_section') }}</legend>
            <div class="grid">
                <label>{{ __('masterdata.fields.payment_terms') }}
                    <input type="text" name="payment_terms" value="{{ old('payment_terms', $client->payment_terms ?? 'eom') }}" pattern="prepaid|eom|net_\d{1,3}" required>
                    <small>{{ __('masterdata.clients.payment_terms_hint') }}</small>
                </label>
                <label>{{ __('masterdata.fields.invoice_mode') }}
                    <select name="invoice_mode">
                        @foreach (\App\Support\Enums::INVOICE_MODES as $v)
                            <option value="{{ $v }}" @selected(old('invoice_mode', $client->invoice_mode ?? 'per_job') === $v)>{{ __('masterdata.invoice_modes.'.$v) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('masterdata.fields.invoice_period') }}
                    <select name="invoice_period">
                        @foreach (\App\Support\Enums::INVOICE_PERIODS as $v)
                            <option value="{{ $v }}" @selected(old('invoice_period', $client->invoice_period ?? 'monthly') === $v)>{{ __('masterdata.invoice_periods.'.$v) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('masterdata.fields.invoice_grouping') }}
                    <select name="invoice_grouping">
                        @foreach (\App\Support\Enums::INVOICE_GROUPINGS as $v)
                            <option value="{{ $v }}" @selected(old('invoice_grouping', $client->invoice_grouping ?? 'job') === $v)>{{ __('masterdata.invoice_groupings.'.$v) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ __('masterdata.fields.default_markup_percent') }}
                    <input type="number" step="0.01" min="0" name="default_markup_percent" value="{{ old('default_markup_percent', $client->default_markup_percent ?? 0) }}" required>
                    <small>{{ __('masterdata.clients.markup_hint') }}</small>
                </label>
                <label>{{ __('masterdata.fields.dispatch_cutoff_time') }}
                    <input type="time" name="dispatch_cutoff_time" value="{{ old('dispatch_cutoff_time', $client->dispatch_cutoff_time ? substr($client->dispatch_cutoff_time, 0, 5) : '') }}">
                    <small>{{ __('masterdata.clients.cutoff_hint') }}</small>
                </label>
            </div>
            <p class="text-muted"><small>{{ __('masterdata.clients.standard_card_hint') }}</small></p>
        </fieldset>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('masterdata.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
