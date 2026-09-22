@extends('layouts.app')

@section('title', __('masterdata.clients.edit'))

@section('content')
    {{-- Edit only (CHANGE_REQUESTS #134): clients are created by self-registration at /register, never by staff. --}}
    <h1>{{ __('masterdata.clients.edit') }}</h1>
    <article>
        <p style="margin:0">
            <strong>{{ __('masterdata.clients.standard_card') }}:</strong>
            @if ($standardCard)
                @role('admin|finance')<a href="{{ route('billing.rate_cards.show', $standardCard) }}">{{ $standardCard->name }} · v{{ $standardCard->version }}</a>@else{{ $standardCard->name }} · v{{ $standardCard->version }}@endrole
            @else
                <mark>{{ __('masterdata.clients.no_standard_card') }}</mark>
            @endif
            &nbsp;·&nbsp;
            <strong>{{ __('masterdata.clients.own_card') }}:</strong>
            @if ($ownCard)
                {{ __('masterdata.clients.own_card_yes') }}({{ $ownCard->name }} · v{{ $ownCard->version }})
            @else
                {{ __('masterdata.clients.own_card_no') }}
            @endif
        </p>
        @if (! $standardCard)
            @role('admin')
                <form method="post" action="{{ route('masterdata.clients.bind_standard_card', $client) }}" style="margin:.5rem 0 0">@csrf <button type="submit" class="outline" style="width:auto;margin:0">{{ __('masterdata.clients.bind_standard_card') }}</button></form>
            @endrole
        @endif
        <p class="text-muted" style="margin:.5rem 0 0"><small>{{ __('masterdata.clients.standard_card_hint') }}</small></p>
    </article>
    <form method="post" action="{{ route('masterdata.clients.update', $client) }}">
        @csrf
        @method('PUT')
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
                    @foreach (\App\Support\Enums::CLIENT_STATUSES as $v)
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
                    <input type="text" name="payment_terms" value="{{ old('payment_terms', $client->payment_terms ?? 'eom') }}" pattern="prepaid|eom|net_\d{1,3}" title="{{ __('masterdata.clients.payment_terms_hint') }}" required>
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
                    <input type="number" step="0.01" min="0" max="999.99" name="default_markup_percent" value="{{ old('default_markup_percent', $client->default_markup_percent ?? 0) }}" required>
                    <small>{{ __('masterdata.clients.markup_hint') }}</small>
                </label>
                <label>{{ __('masterdata.fields.dispatch_cutoff_time') }}
                    <input type="time" name="dispatch_cutoff_time" value="{{ old('dispatch_cutoff_time', $client->dispatch_cutoff_time ? substr($client->dispatch_cutoff_time, 0, 5) : '') }}">
                    <small>{{ __('masterdata.clients.cutoff_hint') }}</small>
                </label>
            </div>
        </fieldset>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('masterdata.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
