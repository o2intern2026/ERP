<?php

namespace App\Modules\Transport\Consumers;

use App\Modules\Transport\Services\ShipmentIntakeService;
use App\Support\Outbox\EventConsumer;

/** order.confirmed -> create the outbound shipment and request its first quote stage. */
final class OrderConfirmedConsumer implements EventConsumer
{
    public function __construct(private readonly ShipmentIntakeService $shipments) {}

    public function handle(array $envelope): void
    {
        if (($envelope['payload']['order_type'] ?? null) === 'return') {
            return;
        }

        $this->shipments->fromConfirmedOrder($envelope);
    }
}
