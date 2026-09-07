<?php

namespace App\Modules\Warehouse\Services;

/** B14: the warehouse a user is working in (session); list pages default-filter by it, null = all warehouses. */
final class WarehouseContext
{
    public const SESSION_KEY = 'warehouse.current_id';

    public static function currentId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return $id ? (int) $id : null;
    }

    public static function set(?int $warehouseId): void
    {
        $warehouseId ? session([self::SESSION_KEY => $warehouseId]) : session()->forget(self::SESSION_KEY);
    }
}
