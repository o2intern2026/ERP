<?php

namespace App\Modules\Orders\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md `return.requested` — payload built by the Orders services, published inside the business transaction. */
final class ReturnRequested extends DomainEvent
{
    public function name(): string
    {
        return 'return.requested';
    }
}
