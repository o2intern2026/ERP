<?php

namespace App\Modules\Transport\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `shipment.quote_confirmed`. */
final class ShipmentQuoteConfirmed extends DomainEvent
{
    public function name(): string
    {
        return 'shipment.quote_confirmed';
    }
}
