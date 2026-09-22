<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * B10b stocktake. `kind` (CHANGE_REQUESTS #142): `full` = opened by a person over a scope; `discrepancy` = the warehouse's running
 * 差异盘点 that short picks with reason 找不到 append one line per unit to (created on first use, one open per warehouse).
 */
class Stocktake extends Model
{
    public const KIND_FULL = 'full';

    public const KIND_DISCREPANCY = 'discrepancy';

    public const KINDS = [self::KIND_FULL, self::KIND_DISCREPANCY];

    protected $fillable = ['stocktake_no', 'warehouse_id', 'client_id', 'location_id', 'status', 'kind', 'started_by', 'closed_by', 'notes', 'closed_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }

    public function isDiscrepancy(): bool
    {
        return $this->kind === self::KIND_DISCREPANCY;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
