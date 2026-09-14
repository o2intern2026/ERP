<?php

namespace App\Support;

use DateTimeInterface;

/**
 * The "urgent despatch" rule (charge-codes.md #21, threshold `cutoff_source` = clients.dispatch_cutoff_time), shared by
 * Orders (`OrderEstimateService::isUrgent`), Transport (`shipment.quote_confirmed` `is_urgent` for pickup_deliver —
 * CHANGE_REQUESTS #120) and Billing tests: an order is urgent when the client asked for it on the very day it is
 * confirmed and the confirmation came after the client's dispatch cut-off. The cut-off is client data, never a constant.
 */
final class UrgentDespatch
{
    /**
     * @param  string|null  $requestedDate  the order's requested_date as Y-m-d (a longer date-time string is read by its date part)
     * @param  string|null  $cutoffTime  the client's dispatch cut-off as H:i or H:i:s
     * @param  DateTimeInterface  $at  the moment of confirmation (compared in its own timezone)
     * @return bool true only when both inputs are non-blank, $requestedDate is $at's calendar day and $at's time (H:i:s) is strictly later than the cut-off
     */
    public static function isUrgent(?string $requestedDate, ?string $cutoffTime, DateTimeInterface $at): bool
    {
        $requestedDate = trim((string) $requestedDate);
        $cutoffTime = trim((string) $cutoffTime);
        if ($requestedDate === '' || $cutoffTime === '') {
            return false;
        }
        if (substr($requestedDate, 0, 10) !== $at->format('Y-m-d')) {
            return false;
        }

        return $at->format('H:i:s') > self::normaliseCutoff($cutoffTime);
    }

    /** H:i → H:i:00 so the comparison is like-for-like with H:i:s; longer strings are read as H:i:s. */
    private static function normaliseCutoff(string $cutoff): string
    {
        return strlen($cutoff) === 5 ? $cutoff.':00' : substr($cutoff, 0, 8);
    }
}
