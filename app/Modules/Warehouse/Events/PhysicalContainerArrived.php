<?php

namespace App\Modules\Warehouse\Events;

use App\Support\Events\DomainEvent;

/**
 * contracts/events.md — `physical_container.arrived` (CHANGE_REQUESTS #122): a shared box was delivered to the warehouse;
 * Billing allocates cartage (TR-CARTAGE-20/40, when cartage_by_us) and the sideloader surcharge over the members' shares.
 * The envelope has no job / client (the box spans clients); members[] carries the split. Payload built by PhysicalContainerService.
 */
final class PhysicalContainerArrived extends DomainEvent
{
    public function name(): string
    {
        return 'physical_container.arrived';
    }
}
