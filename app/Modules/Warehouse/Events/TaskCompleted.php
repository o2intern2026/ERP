<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/** contracts/events.md — `task.completed`. Payload is built by the Warehouse service that owns the business write. */
final class TaskCompleted extends DomainEvent
{
    public function name(): string
    {
        return 'task.completed';
    }
}
