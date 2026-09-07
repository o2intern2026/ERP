<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `stock.reservation_failed`. Payload is built by the Warehouse service that owns the business write. */
final class StockReservationFailed extends DomainEvent
{
    public function name(): string
    {
        return 'stock.reservation_failed';
    }
}
