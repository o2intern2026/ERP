{{-- Shared body of the framework error pages (404 / 405 / 409 / 413 / 419 / 429 / 4xx / 500 / 503 / 5xx), same look as errors/403.
     $key names the platform.errors.<key> group; $backLabel overrides the back-button text; $extra is an optional extra line (429 wait time).
     A custom abort() reason is shown only when it is Chinese AND the exception is an explicit abort() (no wrapped previous exception):
     Laravel's own English defaults never reach the page, and neither does the message of an internal failure (a 500 wraps the real
     exception — SQL with bound data, stack text — which must never be echoed). 500 / 503 pass 'showReason' => false as well. --}}
@php($user = auth()->user())
@php($showReason = ($showReason ?? true) && isset($exception) && $exception->getPrevious() === null)
@php($reason = $showReason ? trim((string) $exception->getMessage()) : '')
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
