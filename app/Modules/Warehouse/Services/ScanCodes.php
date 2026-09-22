<?php

namespace App\Modules\Warehouse\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Short scan tokens (CHANGE_REQUESTS #131, audit 2026-09-22 INBOUND-01). Code 128 of the 24-character label_code ran 146 mm on a
 * label whose printable width is 88 mm, so the barcode on a unit label now encodes `U<stock_unit_id>` and a location label
 * `L<location_id>` (≤ 65 mm for any id below a trillion); the printed text keeps the full label_code / full_code. Every scan input
 * — 扫码 page, putaway, move / restore, stocktake open + count — accepts either form, case-insensitively, through the model scopes
 * StockUnit::scanCode() / Location::scanCode() that delegate here.
 */
final class ScanCodes
{
    public const UNIT_PREFIX = 'U';

    public const LOCATION_PREFIX = 'L';

    public static function unit(int $id): string
    {
        return self::UNIT_PREFIX.$id;
    }

    public static function location(int $id): string
    {
        return self::LOCATION_PREFIX.$id;
    }

    /** What every scan input does with what the gun typed: trimmed, upper-cased (label codes and tokens are upper case). */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function unitId(string $code): ?int
    {
        return self::id($code, self::UNIT_PREFIX);
    }

    public static function locationId(string $code): ?int
    {
        return self::id($code, self::LOCATION_PREFIX);
    }

    /** Constrain a StockUnit query to the unit a scan names: the short token or the full label_code. */
    public static function whereUnit(Builder $query, string $code): Builder
    {
        return self::where($query, $code, 'label_code', self::unitId($code));
    }

    /** Constrain a Location query to the location a scan names: the short token or the full full_code. */
    public static function whereLocation(Builder $query, string $code): Builder
    {
        return self::where($query, $code, 'full_code', self::locationId($code));
    }

    private static function where(Builder $query, string $code, string $column, ?int $id): Builder
    {
        $code = self::normalize($code);

        return $query->where(function (Builder $q) use ($code, $column, $id): void {
            $q->where($q->qualifyColumn($column), $code);
            if ($id !== null) {
                $q->orWhere($q->qualifyColumn('id'), $id);
            }
        });
    }

    private static function id(string $code, string $prefix): ?int
    {
        return preg_match('/^'.$prefix.'(\d{1,18})$/', self::normalize($code), $m) === 1 ? (int) $m[1] : null;
    }
}
