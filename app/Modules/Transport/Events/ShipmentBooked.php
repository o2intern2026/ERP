<?php

namespace App\Modules\Transport\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `shipment.booked`. */
final class ShipmentBooked extends DomainEvent
{
    public function name(): string
    {
        return 'shipment.booked';
    }
}
