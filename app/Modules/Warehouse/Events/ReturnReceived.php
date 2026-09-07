<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `return.received`. Payload is built by the Warehouse service that owns the business write. */
final class ReturnReceived extends DomainEvent
{
    public function name(): string
    {
        return 'return.received';
    }
}
