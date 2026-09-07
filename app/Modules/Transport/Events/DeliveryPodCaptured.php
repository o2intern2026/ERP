<?php

namespace App\Modules\Transport\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `delivery.pod_captured`. */
final class DeliveryPodCaptured extends DomainEvent
{
    public function name(): string
    {
        return 'delivery.pod_captured';
    }
}
