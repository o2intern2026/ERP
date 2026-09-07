<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['stocktake_id', 'stock_unit_id', 'expected_qty', 'counted_qty', 'variance', 'reason', 'scanned', 'counted_by', 'counted_at', 'adjusted_at'];

    protected function casts(): array
    {
        return ['expected_qty' => 'integer', 'counted_qty' => 'integer', 'variance' => 'integer', 'scanned' => 'boolean', 'counted_at' => 'datetime', 'adjusted_at' => 'datetime'];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_qty !== null;
    }
}
