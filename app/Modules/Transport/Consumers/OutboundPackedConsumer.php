<?php

namespace App\Modules\Transport\Consumers;

use App\Modules\Transport\Services\ShipmentIntakeService;
use App\Support\Outbox\EventConsumer;

/** outbound.packed -> bind the real fulfilment/packages and request the final quote. */
final class OutboundPackedConsumer implements EventConsumer
{
    public function __construct(private readonly ShipmentIntakeService $shipments) {}

    public function handle(array $envelope): void
    {
        $this->shipments->fromPackedOutbound($envelope);
    }
}
