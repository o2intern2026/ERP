<?php

namespace App\Support\Outbox;

use App\Support\Events\DomainEvent;

/**
 * Transactional outbox publisher (ERP_PLAN §0.2 rule 4; contracts/events.md).
 *
 * Call publish() INSIDE the DB::transaction() that performs the business write so the event row and
 * the business row commit or roll back together. The real implementation (outbox_events table, retry,
 * failed-event queue, alerting) lands in M1/A31; M0 binds a no-op that only logs.
 */
interface OutboxPublisher
{
    public function publish(DomainEvent $event): void;
}
