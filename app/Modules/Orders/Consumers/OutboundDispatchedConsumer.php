<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\FulfilmentService;
use App\Support\Outbox\EventConsumer;

/** contracts/events.md `outbound.dispatched` (CHANGE_REQUESTS #35): the batch left the warehouse; order dispatched once every batch has. */
final class OutboundDispatchedConsumer implements EventConsumer
{
    public function __construct(private readonly FulfilmentService $fulfilments) {}

    public function handle(array $envelope): void
    {
        $this->fulfilments->markDispatched($envelope['payload']);
    }
}
