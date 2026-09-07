<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `return.inspected`. Payload is built by the Warehouse service that owns the business write. */
final class ReturnInspected extends DomainEvent
{
    public function name(): string
    {
        return 'return.inspected';
    }
}
