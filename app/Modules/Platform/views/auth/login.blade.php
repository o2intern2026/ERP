@extends('layouts.app')

@section('title', __('platform.auth.login'))

@section('content')
    <article class="login-card">
        <h1>{{ __('platform.auth.welcome') }}</h1>
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
    </article>
@endsection
