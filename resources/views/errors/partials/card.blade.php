{{-- Shared body of the framework error pages (404 / 419 / 429 / 500 / 503), same look as errors/403. $key names the
     platform.errors.<key> group; $backLabel overrides the back-button text; $extra is an optional extra line (429 wait time).
     A custom abort() message is shown only when it is Chinese — Laravel's own English defaults never reach the page. --}}
@php($user = auth()->user())
@php($reason = isset($exception) ? trim((string) $exception->getMessage()) : '')
@php($reason = preg_match('/\p{Han}/u', $reason) === 1 ? $reason : '')
<article class="login-card">
    <h1>{{ __('platform.errors.'.$key.'.title') }}</h1>
    <p>{{ __('platform.errors.'.$key.'.body') }}</p>
    @if ($reason !== '')<p>{{ __('platform.errors.reason', ['reason' => $reason]) }}</p>@endif
    <p>{{ $extra ?? __('platform.errors.'.$key.'.hint') }}</p>
    <p>
        <a href="javascript:history.back()" role="button" class="secondary">{{ $backLabel ?? __('platform.errors.back') }}</a>
        <a href="{{ $user?->isClientUser() ? route('portal.index') : route('platform.index') }}" role="button">{{ __('platform.errors.home') }}</a>
    </p>
    <p class="text-muted"><small>{{ __('platform.errors.contact') }}</small></p>
</article>
