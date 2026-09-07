<?php

namespace App\Support\Outbox;

use App\Support\Events\DomainEvent;
use Illuminate\Support\Facades\Log;

/** M0 stand-in: records nothing, logs the envelope. Replaced by the outbox_events implementation in M1/A31. */
final class LogOutboxPublisher implements OutboxPublisher
{
    public function publish(DomainEvent $event): void
    {
        Log::info('outbox.publish (no-op until M1/A31)', $event->toArray());
    }
}
