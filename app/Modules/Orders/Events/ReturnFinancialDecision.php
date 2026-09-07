<?php

namespace App\Modules\Orders\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md `return.financial_decision` — payload built by the Orders services, published inside the business transaction. */
final class ReturnFinancialDecision extends DomainEvent
{
    public function name(): string
    {
        return 'return.financial_decision';
    }
}
