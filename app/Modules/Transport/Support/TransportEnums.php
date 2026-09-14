<?php

namespace App\Modules\Transport\Support;

use InvalidArgumentException;

/** Module-local mirror of contracts/enums.md §5. Values must remain verbatim. */
final class TransportEnums
{
    /** `inbound_collection` = 我方上门提货 for a 预报单 (CHANGE_REQUESTS #124): sender = the client's pickup address, receiver = our warehouse, no order. */
    public const SHIPMENT_TYPES = ['outbound', 'return', 'inbound_collection'];

    /** Shipment types that run the delivery lifecycle (quote → book → run / carrier → POD); `return` has its own statuses. */
    public const DELIVERY_TYPES = ['outbound', 'inbound_collection'];

    public const OUTBOUND_STATUSES = [
        'quoting', 'quoted', 'quote_confirmed', 'booked', 'dispatched', 'in_transit', 'delivered',
        'failed', 'booking_cancelled',
    ];

    public const RETURN_STATUSES = ['return_requested', 'return_in_transit', 'arrived_warehouse'];

    public const QUOTE_STAGES = ['preliminary', 'final'];

    public const QUOTE_STATUSES = ['quoted', 'selected', 'expired', 'requoted', 'booking_cancelled'];

    public const SELECTED_BY = ['client', 'coordinator', 'system'];

    public const SOURCES = ['own_fleet', 'transdirect', 'eiz', 'manual', 'karrio'];

    public const SERVICE_LEVELS = ['standard', 'express', 'same_day'];

    public const DELIVERY_RUN_STATUSES = ['planned', 'dispatched', 'completed', 'cancelled'];

    public const RUN_STOP_STATUSES = ['pending', 'arrived', 'delivered', 'failed'];

    public const TRACKING_SOURCES = ['api', 'driver', 'manual'];

    public const CARRIER_INVOICE_STATUSES = ['received', 'matched', 'disputed', 'paid'];

    /** Outbound or inbound collection: quotes, booking, runs, POD and tracking apply; a return shipment does not (#124). */
    public static function isDelivery(?string $shipmentType): bool
    {
        return in_array($shipmentType, self::DELIVERY_TYPES, true);
    }

    /** @return list<string> */
    public static function shipmentStatuses(string $shipmentType): array
    {
        return match ($shipmentType) {
            'outbound', 'inbound_collection' => self::OUTBOUND_STATUSES,
            'return' => self::RETURN_STATUSES,
            default => throw new InvalidArgumentException("Unknown shipment type: {$shipmentType}"),
        };
    }
}
