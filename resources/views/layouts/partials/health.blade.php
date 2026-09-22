{{-- Frozen zone (seat C). CR #137 (audit ADMIN-12): admins see on every page when the outbox backlog has stopped moving — a stopped cron
     is otherwise silent. One cached query (HealthCheck::staleBanner, 60 s); nothing for other roles or guests. --}}
@auth
    @if (auth()->user()->hasRole('admin'))
        @php($stale = app(\App\Modules\Platform\Services\HealthCheck::class)->staleBanner())
        @if ($stale !== null)
            <article class="flash flash-error erp-health" role="alert">
                <strong>{{ __('platform.health.banner_title') }}</strong>
                {{ __('platform.health.banner', ['minutes' => $stale['minutes'], 'pending' => $stale['pending']]) }}
                <a href="{{ route('platform.integration.index', ['status' => 'pending']) }}">{{ __('platform.health.open_monitor') }}</a>
            </article>
        @endif
    @endif
@endauth
