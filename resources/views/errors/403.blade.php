@extends('layouts.app')

@section('title', __('platform.errors.forbidden.title'))

@section('content')
    @php($user = auth()->user())
    @php($roles = \App\Support\Auth\RequiredRoles::resolve(request(), $exception ?? null))
    @php($reason = isset($exception) ? trim((string) $exception->getMessage()) : '')
    @php($reason = in_array($reason, ['', 'Forbidden', 'This action is unauthorized.', 'User does not have the right roles.', 'User does not have any of the necessary access rights.'], true) ? '' : $reason)
    <article class="login-card">
        <h1>{{ __('platform.errors.forbidden.title') }}</h1>
        <p>{{ __('platform.errors.forbidden.body') }}</p>
        @if ($reason !== '')<p>{{ __('platform.errors.forbidden.reason', ['reason' => $reason]) }}</p>@endif
        @if ($user)
            <p class="text-muted"><small>{{ __('platform.errors.forbidden.you_are', ['name' => $user->name, 'roles' => $user->getRoleNames()->map(fn ($r) => __('platform.roles.'.$r))->join(' / ') ?: '—']) }}</small></p>
            @if ($user->isClientUser())
                <p>{{ __('platform.errors.forbidden.client_hint') }}</p>
            @elseif ($roles !== [])
                <p><strong>{{ __('platform.errors.forbidden.required', ['roles' => collect($roles)->map(fn ($r) => __('platform.roles.'.$r))->join(' / ')]) }}</strong></p>
            @else
                <p>{{ __('platform.errors.forbidden.required_unknown') }}</p>
            @endif
        @endif
        <p>
            <a href="javascript:history.back()" role="button" class="secondary">{{ __('platform.errors.forbidden.back') }}</a>
            <a href="{{ $user?->isClientUser() ? route('portal.index') : route('platform.index') }}" role="button">{{ __('platform.errors.forbidden.home') }}</a>
        </p>
        <p class="text-muted"><small>{{ __('platform.errors.forbidden.contact') }}</small></p>
    </article>
@endsection
