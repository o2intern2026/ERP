@extends('layouts.app')

@section('title', __('platform.password.title'))

@section('content')
    {{-- CR #137 (audit ADMIN-13): every signed-in user changes their own password here; linked from the user name in the nav. --}}
    <article class="login-card">
        <h1>{{ __('platform.password.title') }}</h1>
        <p class="text-muted"><small>{{ __('platform.password.hint') }}</small></p>
        <form method="post" action="{{ route('platform.password.update') }}">
            @csrf
            @method('PUT')
            <label>{{ __('platform.password.current') }}
                <input type="password" name="current_password" required autocomplete="current-password" @error('current_password') aria-invalid="true" @enderror>
            </label>
            <label>{{ __('platform.password.new') }}
                <input type="password" name="password" required autocomplete="new-password" minlength="8" @error('password') aria-invalid="true" @enderror>
            </label>
            <label>{{ __('platform.password.confirm') }}
                <input type="password" name="password_confirmation" required autocomplete="new-password" minlength="8">
            </label>
            <button type="submit">{{ __('platform.password.submit') }}</button>
        </form>
        <p><a href="{{ \App\Modules\Platform\Http\Controllers\LoginController::landingUrl(auth()->user()) }}">← {{ __('platform.common.back') }}</a></p>
    </article>
@endsection
