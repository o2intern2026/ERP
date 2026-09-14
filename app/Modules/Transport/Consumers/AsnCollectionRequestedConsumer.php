<?php

namespace App\Modules\Transport\Consumers;

use App\Modules\Transport\Services\ShipmentIntakeService;
use App\Support\Outbox\EventConsumer;

/** asn.collection_requested → open (or re-quote) the inbound collection shipment for the 预报单 and quote it at the final stage (CHANGE_REQUESTS #124). */
final class AsnCollectionRequestedConsumer implements EventConsumer
{
    public function __construct(private readonly ShipmentIntakeService $shipments) {}

    public function handle(array $envelope): void
    {
        $this->shipments->fromAsnCollection($envelope);
    }
}
