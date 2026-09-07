<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Four-level location: warehouse / zone / aisle / bin → full_code, e.g. MEL-A-01-03 (ERP_PLAN §4.2). */
class Location extends Model
{
    protected $fillable = ['warehouse_id', 'zone', 'aisle', 'bin', 'full_code', 'type', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public static function buildFullCode(string $warehouseCode, string $zone, string $aisle, string $bin): string
    {
        return strtoupper(implode('-', [$warehouseCode, $zone, $aisle, $bin]));
    }
}
