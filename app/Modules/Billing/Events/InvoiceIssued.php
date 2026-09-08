<?php

namespace App\Modules\Billing\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `invoice.issued`: Orders moves billing_status to billed, Platform rolls revenue onto the Job. */
final class InvoiceIssued extends DomainEvent
{
    public function name(): string
    {
        return 'invoice.issued';
    }
}
