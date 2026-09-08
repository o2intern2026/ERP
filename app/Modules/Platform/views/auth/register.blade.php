@extends('layouts.app')

@section('title', __('platform.auth.register_title'))

@section('content')
    <article class="login-card register-card">
        <h1>{{ __('platform.auth.register_title') }}</h1>
        <p class="text-muted"><small>{{ __('platform.auth.register_hint') }}</small></p>

        @if ($errors->any())
            <article><ul style="margin:0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></article>
        @endif

        <form method="post" action="{{ route('platform.register.store') }}">
            @csrf
            <h2>{{ __('platform.auth.company') }}</h2>
            <div class="grid">
                <label>{{ __('platform.auth.company_name') }}<input type="text" name="company_name" value="{{ old('company_name') }}" required maxlength="255" autofocus autocomplete="organization"></label>
                <label>{{ __('platform.auth.abn') }}<input type="text" name="abn" value="{{ old('abn') }}" maxlength="20" placeholder="11 位数字,可选"></label>
            </div>
            <div class="grid">
                <label>{{ __('platform.auth.contact_name') }}<input type="text" name="contact_name" value="{{ old('contact_name') }}" required maxlength="255" autocomplete="name"></label>
                <label>{{ __('platform.auth.contact_phone') }}<input type="tel" name="contact_phone" value="{{ old('contact_phone') }}" required maxlength="40" autocomplete="tel"></label>
            </div>
            <label>{{ __('platform.auth.address') }}<input type="text" name="address" value="{{ old('address') }}" maxlength="255" autocomplete="street-address"></label>
            <div class="grid">
                <label>{{ __('platform.auth.suburb') }}<input type="text" name="suburb" value="{{ old('suburb') }}" maxlength="100"></label>
                <label>{{ __('platform.auth.state') }}
                    <select name="state">
                        <option value="">—</option>
                        @foreach ($states as $state)<option value="{{ $state }}" @selected(old('state') === $state)>{{ $state }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('platform.auth.postcode') }}<input type="text" name="postcode" value="{{ old('postcode') }}" maxlength="10" inputmode="numeric" autocomplete="postal-code"></label>
            </div>

            <h2>{{ __('platform.auth.account') }}</h2>
            <label>{{ __('platform.auth.email') }}<input type="email" name="email" value="{{ old('email') }}" required autocomplete="username"></label>
            <div class="grid">
                <label>{{ __('platform.auth.password') }}<input type="password" name="password" required minlength="8" autocomplete="new-password"><small>{{ __('platform.auth.password_hint') }}</small></label>
                <label>{{ __('platform.auth.password_confirmation') }}<input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
            </div>
            <button type="submit">{{ __('platform.auth.register') }}</button>
        </form>
        <p class="text-muted"><small><a href="{{ route('platform.login') }}">{{ __('platform.auth.login_link') }}</a></small></p>
    </article>
@endsection
