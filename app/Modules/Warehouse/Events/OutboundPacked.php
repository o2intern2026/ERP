<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `outbound.packed`. Payload is built by the Warehouse service that owns the business write. */
final class OutboundPacked extends DomainEvent
{
    public function name(): string
    {
        return 'outbound.packed';
    }
}
