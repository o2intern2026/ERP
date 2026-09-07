<?php

namespace App\Modules\Transport\Consumers;

use App\Modules\Transport\Services\ShipmentIntakeService;
use App\Support\Outbox\EventConsumer;

/** outbound.dispatched -> record that a linked booked shipment has left the warehouse. */
final class OutboundDispatchedConsumer implements EventConsumer
{
    public function __construct(private readonly ShipmentIntakeService $shipments) {}

    public function handle(array $envelope): void
    {
        $this->shipments->fromDispatchedOutbound($envelope);
    }
}
