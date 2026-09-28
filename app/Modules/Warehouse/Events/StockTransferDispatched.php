<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `stock.transfer.dispatched` (CHANGE_REQUESTS #167). Payload is built by StockTransferService. */
final class StockTransferDispatched extends DomainEvent
{
    public function name(): string
    {
        return 'stock.transfer.dispatched';
    }
}
