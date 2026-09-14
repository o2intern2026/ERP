<?php

namespace App\Modules\Transport\Consumers;

use App\Modules\Transport\Services\ShipmentIntakeService;
use App\Support\Outbox\EventConsumer;

/** asn.collection_cancelled (改为客户自送) → cancel the collection shipment while it is not booked (CHANGE_REQUESTS #124). */
final class AsnCollectionCancelledConsumer implements EventConsumer
{
    public function __construct(private readonly ShipmentIntakeService $shipments) {}

    public function handle(array $envelope): void
    {
        $this->shipments->cancelAsnCollection($envelope);
    }
}
