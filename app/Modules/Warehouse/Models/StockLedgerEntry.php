<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only movement row (ERP_PLAN §4.2 stock_ledger). Never updated or deleted. */
class StockLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'stock_ledger';

    protected $fillable = [
        'stock_unit_id', 'movement_type', 'qty', 'qty_before', 'qty_after', 'movement_group_id',
        'from_stock_unit_id', 'to_stock_unit_id', 'from_location_id', 'to_location_id',
        'source_type', 'source_id', 'operator_id', 'created_at',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'qty_before' => 'integer', 'qty_after' => 'integer', 'created_at' => 'datetime'];
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
