<?php

namespace App\Modules\Orders;

use Illuminate\Support\Facades\Lang;

/** Orders values copied verbatim from contracts/enums.md §3 (plus the form-only PACKAGE_TYPES list, CHANGE_REQUESTS #76). */
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

    /**
     * Package types offered by the order forms (goods lines and declared packages). Not yet in contracts/enums.md §3 —
     * CHANGE_REQUESTS #76 asks C to add it; Warehouse's `packages.package_type` (carton | pallet | satchel | crate) is a subset.
     * Rows created through the API, Excel, PDF or ASN paths may still carry other values: render them with packageTypeLabel().
     */
    public const PACKAGE_TYPES = ['carton', 'satchel', 'pallet', 'crate', 'tube', 'flat_pack', 'skid'];

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

    /** Chinese + code label for a package type; values outside PACKAGE_TYPES (legacy / API data) fall back to the raw value. */
    public static function packageTypeLabel(?string $type): string
    {
        if ($type === null || $type === '') {
            return __('orders.not_provided');
        }

        return Lang::has('orders.package_types.'.$type) ? __('orders.package_types.'.$type) : $type;
    }
}
