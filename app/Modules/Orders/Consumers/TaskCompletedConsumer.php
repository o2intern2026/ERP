<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\FulfilmentService;
use App\Support\Outbox\EventConsumer;

/** contracts/events.md `task.completed`: a finished pick task moves its batch (and the order, from allocated) to picking. */
final class TaskCompletedConsumer implements EventConsumer
{
    public function __construct(private readonly FulfilmentService $fulfilments) {}

    public function handle(array $envelope): void
    {
        $this->fulfilments->markPicking($envelope['payload']);
    }
}
