<?php

namespace App\Modules\Orders\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md `order.reduced` — payload built by the Orders services, published inside the business transaction. */
final class OrderReduced extends DomainEvent
{
    public function name(): string
    {
        return 'order.reduced';
    }
}
