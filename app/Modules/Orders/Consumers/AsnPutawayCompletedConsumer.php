<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\FulfilmentService;
use App\Support\Outbox\EventConsumer;

/** New stock on a goods line is the automatic retry signal for A7 backorders. */
final class AsnPutawayCompletedConsumer implements EventConsumer
{
    public function __construct(private readonly FulfilmentService $fulfilments) {}

    public function handle(array $envelope): void
    {
        $this->fulfilments->allocateBackordersFromPutaway($envelope['payload']);
    }
}
