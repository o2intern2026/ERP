<?php

namespace App\Support;

/**
 * Declared package type → billing unit type (CHANGE_REQUESTS #120, 2026-09-14).
 *
 * A pickup_deliver order never reaches the warehouse, so its handling charges and its estimate come from what the client
 * declared (orders.declared_packages). The package type decides whether a piece is billed as a pallet (WH-PICK-PLT,
 * WH-LOAD-PLT) or as a carton (WH-PICK-CTN-* banded on the declared per-piece weight). Orders (estimate), Transport
 * (`shipment.quote_confirmed` lines) and Billing all call this so the three never disagree.
 */
final class PackageUnits
{
    public const PALLET = 'pallet';

    public const CARTON = 'carton';

    /** Package types billed as a pallet; every other type (carton, box, satchel, bag, tube, …) is a loose piece. */
    public const PALLET_TYPES = ['pallet', 'skid'];

    /**
     * 'pallet' when the declared package type is a pallet or skid (case-insensitive, surrounding whitespace ignored),
     * otherwise 'carton' — including null / blank, which counts as a loose piece.
     */
    public static function unitType(?string $packageType): string
    {
        return in_array(strtolower(trim((string) $packageType)), self::PALLET_TYPES, true) ? self::PALLET : self::CARTON;
    }
}
