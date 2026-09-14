<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\FulfilmentService;
use App\Support\Outbox\EventConsumer;

/** contracts/events.md: each POD closes one batch; the order closes only after every ordered carton is delivered. */
final class DeliveryPodCapturedConsumer implements EventConsumer
{
    public function __construct(private readonly FulfilmentService $fulfilments) {}

    public function handle(array $envelope): void
    {
        if (($envelope['payload']['order_id'] ?? null) === null) {
            return; // an inbound collection for a 预报单 (CHANGE_REQUESTS #124) has no order behind it — Warehouse consumes that POD
        }

        $this->fulfilments->markDelivered($envelope['payload']);
    }
}
