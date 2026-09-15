<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Four-level location: warehouse / zone / aisle / bin → full_code, e.g. MEL-A-01-03 (ERP_PLAN §4.2).
 * CHANGE_REQUESTS #126: storage_tier (standard | bottom, storage locations only) and rack_level (1 = floor / bottom beam); tier and
 * level changes are logged (activitylog `location`).
 */
class Location extends Model
{
    use LogsActivity;

    protected $fillable = ['warehouse_id', 'zone', 'aisle', 'bin', 'full_code', 'type', 'storage_tier', 'rack_level', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'rack_level' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** A bottom-level storage location (#126) — the only kind that carries the bottom-level surcharge. */
    public function isBottom(): bool
    {
        return $this->type === 'storage' && $this->storage_tier === 'bottom';
    }

    public static function buildFullCode(string $warehouseCode, string $zone, string $aisle, string $bin): string
    {
        return strtoupper(implode('-', [$warehouseCode, $zone, $aisle, $bin]));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['storage_tier', 'rack_level'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('location');
    }
}
