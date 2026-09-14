<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `asn.collection_cancelled` (CHANGE_REQUESTS #124): 改为客户自送 before booking; Transport cancels the unbooked shipment. */
final class AsnCollectionCancelled extends DomainEvent
{
    public function name(): string
    {
        return 'asn.collection_cancelled';
    }
}
