<?php

namespace App\Support\Events;

use Illuminate\Support\Str;

/**
 * Base class for every cross-module event listed in contracts/events.md.
 *
 * A subclass returns its event name from name() (e.g. 'asn.putaway_completed') and builds $payload
 * with exactly the fields contracts/events.md lists — nothing less, additive extras allowed.
 * Events are published only through App\Support\Outbox\OutboxPublisher, inside the SAME database
 * transaction as the business write (ERP_PLAN §0.2 rule 4). Money in payloads is integer cents.
 */
abstract class DomainEvent
{
    public readonly string $eventId;

    public readonly string $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly ?int $jobId = null,
        public readonly ?int $clientId = null,
        public readonly ?string $correlationId = null,
        ?string $eventId = null,
        ?string $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
    }

    /** Event name exactly as in contracts/events.md. */
    abstract public function name(): string;

    /** Payload schema version; bump only on breaking payload changes (consumers check it). */
    public function version(): int
    {
        return 1;
    }

    /**
     * The outbox envelope (contracts/events.md §"Envelope").
     *
     * @return array{event_id:string, event_name:string, event_version:int, correlation_id:?string, job_id:?int, client_id:?int, occurred_at:string, payload:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->name(),
            'event_version' => $this->version(),
            'correlation_id' => $this->correlationId,
            'job_id' => $this->jobId,
            'client_id' => $this->clientId,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
        ];
    }
}
