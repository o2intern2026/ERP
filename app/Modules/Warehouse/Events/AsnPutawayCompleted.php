<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `asn.putaway_completed`. Payload is built by the Warehouse service that owns the business write. */
final class AsnPutawayCompleted extends DomainEvent
{
    public function name(): string
    {
        return 'asn.putaway_completed';
    }
}
