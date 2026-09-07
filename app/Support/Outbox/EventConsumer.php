<?php

namespace App\Support\Outbox;

/**
 * A consumer of one event from contracts/events.md. Register it in your module's ServiceProvider::boot():
 *
 *     $this->app->make(ConsumerRegistry::class)->register('order.confirmed', ReserveStockConsumer::class);
 *
 * The dispatcher calls handle() inside a DB transaction and records (event_id, consumer) in consumed_events in that
 * same transaction, so a consumer is invoked at most once per event; throw to have the delivery retried (A31 backoff).
 */
interface EventConsumer
{
    /**
     * @param  array{event_id:string, event_name:string, event_version:int, correlation_id:?string, job_id:?int, client_id:?int, occurred_at:?string, payload:array<string, mixed>}  $envelope
     */
    public function handle(array $envelope): void;
}
