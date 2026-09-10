<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\OutboxEvent;
use App\Support\Events\DomainEvent;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Transactional outbox (A31): writes the event row with the caller's open DB transaction, so the business write and
 * the event commit or roll back together (ERP_PLAN §0.2 rule 4). Refuses to publish outside a transaction.
 */
final class DatabaseOutboxPublisher implements OutboxPublisher
{
    public function publish(DomainEvent $event): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("OutboxPublisher::publish({$event->name()}) must run inside the business DB transaction (ERP_PLAN §0.2 rule 4).");
        }

        $row = OutboxEvent::query()->create([
            'event_id' => $event->eventId,
            'event_name' => $event->name(),
            'event_version' => $event->version(),
            'correlation_id' => $event->correlationId,
            'job_id' => $event->jobId,
            'client_id' => $event->clientId,
            'payload' => $event->payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => $event->occurredAt,
        ]);

        // Screen-facing events (config erp.outbox_dispatch_now) are delivered as soon as the business transaction commits — same
        // consumers, same idempotency (row lock + consumed_events), cron remains the safety net. Not while a dispatch run is
        // already delivering (consumers publishing follow-ups): the running chain picks those up hop by hop.
        if (in_array($event->name(), (array) config('erp.outbox_dispatch_now', []), true) && ! OutboxDispatcher::isDispatching()) {
            $rowId = (int) $row->id;
            $jobId = $event->jobId !== null ? (int) $event->jobId : null;
            DB::afterCommit(fn () => app(OutboxDispatcher::class)->dispatchNow($rowId, $jobId));
        }
    }
}
