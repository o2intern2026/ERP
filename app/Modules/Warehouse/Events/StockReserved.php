<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `stock.reserved`. Payload is built by the Warehouse service that owns the business write. */
final class StockReserved extends DomainEvent
{
    public function name(): string
    {
        return 'stock.reserved';
    }
}
