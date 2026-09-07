<?php

namespace Tests\Support\Outbox;

use App\Support\Events\DomainEvent;

/** A DomainEvent for tests; name defaults to test.event. Build payloads from contracts/events.md when faking a real event. */
final class TestEvent extends DomainEvent
{
    public function __construct(array $payload = [], private readonly string $eventName = 'test.event', ?int $jobId = null, ?int $clientId = null)
    {
        parent::__construct($payload, $jobId, $clientId);
    }

    public function name(): string
    {
        return $this->eventName;
    }
}
