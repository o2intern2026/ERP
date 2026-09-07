<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `outbound.dispatched`. Payload is built by the Warehouse service that owns the business write. */
final class OutboundDispatched extends DomainEvent
{
    public function name(): string
    {
        return 'outbound.dispatched';
    }
}
