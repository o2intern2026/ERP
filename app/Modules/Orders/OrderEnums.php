<?php

namespace App\Modules\Orders;

/** Orders values copied verbatim from contracts/enums.md §3. */
final class OrderEnums
{
    public const TYPES = ['from_stock', 'pickup_deliver', 'return'];

    public const SOURCES = ['portal', 'excel', 'api', 'manual', 'pdf'];

    public const OPERATIONAL_STATUSES = ['received', 'confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered', 'returned', 'cancelled'];

    public const FULFILMENT_STATUSES = ['unfulfilled', 'partial', 'fulfilled'];

    public const BILLING_STATUSES = ['unbilled', 'partially_billed', 'billed', 'credited'];

    public const SERVICE_LEVELS = ['standard', 'express', 'same_day'];

    public const TAILGATE_REASONS = ['heavy_item', 'residential_address', 'manual'];

    public const FULFILMENT_BATCH_STATUSES = ['allocated', 'picking', 'packed', 'dispatched', 'delivered'];

    public const ADDRESS_TYPES = ['business', 'fba', 'residential'];

    public const EVENT_DIMENSIONS = ['operational', 'fulfilment', 'billing'];

    public const CUSTOMER_STATUS_MAP = [
        'received' => 'received',
        'confirmed' => 'confirmed',
        'allocated' => 'in_warehouse',
        'picking' => 'in_warehouse',
        'packed' => 'in_warehouse',
        'dispatched' => 'out_for_delivery',
        'delivered' => 'delivered',
        'returned' => 'returned',
        'cancelled' => 'cancelled',
    ];
}
