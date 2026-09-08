<?php

namespace App\Modules\Orders\Consumers;

use App\Modules\Orders\Services\ReturnRequestService;
use App\Support\Outbox\EventConsumer;

/** contracts/events.md `return.inspected`: the return order becomes `returned`; the original order gets a timeline entry. */
final class ReturnInspectedConsumer implements EventConsumer
{
    public function __construct(private readonly ReturnRequestService $returns) {}

    public function handle(array $envelope): void
    {
        $this->returns->applyInspection($envelope['payload']);
    }
}
