<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/**
 * contracts/events.md — `asn.collection_requested` (CHANGE_REQUESTS #124): the coordinator asked Transport to collect the goods of
 * a 预报单 at the client's pickup address and bring them to our warehouse. Transport opens (or re-quotes) the inbound collection
 * shipment; `activity_version` = asns.collection_version so a re-request supersedes the previous one. Payload built by AsnService.
 */
final class AsnCollectionRequested extends DomainEvent
{
    public function name(): string
    {
        return 'asn.collection_requested';
    }
}
