<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\FulfilmentService;
use App\Support\Outbox\EventConsumer;

/** contracts/events.md: a partial reservation becomes a fulfilment; the shortfall remains backordered. */
final class StockReservationFailedConsumer implements EventConsumer
{
    public function __construct(private readonly FulfilmentService $fulfilments) {}

    public function handle(array $envelope): void
    {
        $this->fulfilments->applyReservationResult($envelope['payload']);
    }
}
