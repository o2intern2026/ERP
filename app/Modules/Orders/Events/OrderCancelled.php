<?php

namespace App\Modules\Orders\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md `order.cancelled` — payload built by the Orders services, published inside the business transaction. */
final class OrderCancelled extends DomainEvent
{
    public function name(): string
    {
        return 'order.cancelled';
    }
}
