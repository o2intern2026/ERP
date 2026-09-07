<?php

namespace Tests\Support\Outbox;

use App\Support\Outbox\EventConsumer;

final class RecordingConsumer implements EventConsumer
{
    /** @var list<array<string, mixed>> */
    public static array $seen = [];

    public function handle(array $envelope): void
    {
        self::$seen[] = $envelope;
    }
}
