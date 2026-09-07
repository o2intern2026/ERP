<?php

namespace App\Modules\Billing\Consumers;

use App\Modules\Billing\Services\ChargeEngine;
use App\Support\Outbox\EventConsumer;

/** A6a: every billable operational event (ERP_PLAN §6.4) runs through the charge engine. Idempotent via charge keys. */
final class BillingChargeConsumer implements EventConsumer
{
    public const EVENTS = ['task.completed', 'asn.putaway_completed', 'outbound.packed', 'shipment.quote_confirmed', 'delivery.extra_charge'];

    public function __construct(private readonly ChargeEngine $engine) {}

    public function handle(array $envelope): void
    {
        $this->engine->applyEvent($envelope);
    }
}
