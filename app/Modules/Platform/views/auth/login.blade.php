@extends('layouts.app')

@section('title', __('platform.auth.login'))

@section('content')
    <article class="login-card">
        <h1>{{ __('platform.auth.welcome') }}</h1>
        @if (session('status'))<p role="status"><mark>{{ session('status') }}</mark></p>@endif
        <form method="post" action="{{ route('platform.login.store') }}">
            @csrf
            <label>{{ __('platform.auth.email') }}
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </label>
            <label>{{ __('platform.auth.password') }}
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <label><input type="checkbox" name="remember" value="1"> {{ __('platform.auth.remember') }}</label>
            <button type="submit">{{ __('platform.auth.login') }}</button>
        </form>
        @if (config('erp.allow_signup'))<p class="text-muted"><small><a href="{{ route('platform.register') }}">{{ __('platform.auth.register_link') }}</a></small></p>@endif
    </article>
@endsection
