<?php

namespace Tests\Support\Outbox;

use App\Support\Outbox\EventConsumer;
use RuntimeException;

final class FailingConsumer implements EventConsumer
{
    public function handle(array $envelope): void
    {
        throw new RuntimeException('boom');
    }
}
