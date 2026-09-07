<?php

namespace App\Modules\Transport\Support;

use InvalidArgumentException;

/** Module-local mirror of contracts/enums.md §5. Values must remain verbatim. */
final class TransportEnums
{
    public const SHIPMENT_TYPES = ['outbound', 'return'];

    public const OUTBOUND_STATUSES = [
        'quoting', 'quoted', 'quote_confirmed', 'booked', 'dispatched', 'in_transit', 'delivered',
        'failed', 'booking_cancelled',
    ];

    public const RETURN_STATUSES = ['return_requested', 'return_in_transit', 'arrived_warehouse'];

    public const QUOTE_STAGES = ['preliminary', 'final'];

    public const QUOTE_STATUSES = ['quoted', 'selected', 'expired', 'requoted', 'booking_cancelled'];

    public const SELECTED_BY = ['client', 'coordinator', 'system'];

    public const SOURCES = ['own_fleet', 'transdirect', 'eiz', 'manual'];

    public const SERVICE_LEVELS = ['standard', 'express', 'same_day'];

    public const DELIVERY_RUN_STATUSES = ['planned', 'dispatched', 'completed', 'cancelled'];

    public const RUN_STOP_STATUSES = ['pending', 'arrived', 'delivered', 'failed'];

    public const TRACKING_SOURCES = ['api', 'driver', 'manual'];

    /** @return list<string> */
    public static function shipmentStatuses(string $shipmentType): array
    {
        return match ($shipmentType) {
            'outbound' => self::OUTBOUND_STATUSES,
            'return' => self::RETURN_STATUSES,
            default => throw new InvalidArgumentException("Unknown shipment type: {$shipmentType}"),
        };
    }
}
