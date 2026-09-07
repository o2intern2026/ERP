<?php

namespace App\Modules\Transport\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `delivery.failed`. */
final class DeliveryFailed extends DomainEvent
{
    public function name(): string
    {
        return 'delivery.failed';
    }
}
