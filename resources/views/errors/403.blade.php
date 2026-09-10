@extends('layouts.app')

@section('title', __('platform.errors.forbidden.title'))

@section('content')
    @php($user = auth()->user())
    @php($required = isset($exception) && $exception instanceof \Spatie\Permission\Exceptions\UnauthorizedException ? $exception->getRequiredRoles() : [])
    <article class="login-card">
        <h1>{{ __('platform.errors.forbidden.title') }}</h1>
        <p>{{ __('platform.errors.forbidden.body') }}</p>
        @if ($user)
            <p class="text-muted"><small>{{ __('platform.errors.forbidden.you_are', ['name' => $user->name, 'roles' => $user->getRoleNames()->map(fn ($r) => __('platform.roles.'.$r))->join(' / ') ?: '—']) }}</small></p>
            @if ($user->isClientUser())
                <p>{{ __('platform.errors.forbidden.client_hint') }}</p>
            @elseif ($required !== [])
                <p>{{ __('platform.errors.forbidden.required', ['roles' => collect($required)->map(fn ($r) => __('platform.roles.'.$r))->join(' / ')]) }}</p>
            @endif
        @endif
        <p>
            <a href="javascript:history.back()" role="button" class="secondary">{{ __('platform.errors.forbidden.back') }}</a>
            <a href="{{ $user?->isClientUser() ? route('portal.index') : route('platform.index') }}" role="button">{{ __('platform.errors.forbidden.home') }}</a>
        </p>
        <p class="text-muted"><small>{{ __('platform.errors.forbidden.contact') }}</small></p>
    </article>
@endsection
