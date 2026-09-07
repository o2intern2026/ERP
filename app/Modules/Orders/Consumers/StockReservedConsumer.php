<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\FulfilmentService;
use App\Support\Outbox\EventConsumer;

/** contracts/events.md: stock.reserved → allocated fulfilment batches. */
final class StockReservedConsumer implements EventConsumer
{
    public function __construct(private readonly FulfilmentService $fulfilments) {}

    public function handle(array $envelope): void
    {
        $this->fulfilments->applyReservationResult($envelope['payload']);
    }
}
