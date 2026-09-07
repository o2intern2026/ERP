<?php

namespace App\Support;

use App\Support\Outbox\LogOutboxPublisher;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\ServiceProvider;

/** Frozen zone (app/Support). Binds the shared infrastructure every module depends on. */
final class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // M1/A31 replaces this with the outbox_events publisher.
        $this->app->singleton(OutboxPublisher::class, LogOutboxPublisher::class);
    }
}
