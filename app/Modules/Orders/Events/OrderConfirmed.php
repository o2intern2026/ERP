<?php

namespace App\Modules\Orders\Events;

use App\Support\Events\DomainEvent;

final class OrderConfirmed extends DomainEvent
{
    public function name(): string
    {
        return 'order.confirmed';
    }
}
