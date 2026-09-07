<?php

namespace App\Modules\Transport\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `delivery.extra_charge`. */
final class DeliveryExtraCharge extends DomainEvent
{
    public function name(): string
    {
        return 'delivery.extra_charge';
    }
}
