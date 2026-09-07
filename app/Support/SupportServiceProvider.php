<?php

namespace App\Support;

use App\Support\Outbox\ConsumerRegistry;
use App\Support\Outbox\LogOutboxPublisher;
use App\Support\Outbox\OutboxPublisher;
use App\Support\Search\SearchRegistry;
use Illuminate\Support\ServiceProvider;

/** Frozen zone (app/Support). Binds the shared infrastructure every module depends on. */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConsumerRegistry::class);
        $this->app->singleton(SearchRegistry::class);

        // Fallback only: PlatformServiceProvider (registered later) binds the real outbox_events publisher.
        $this->app->singleton(OutboxPublisher::class, LogOutboxPublisher::class);
    }
}
