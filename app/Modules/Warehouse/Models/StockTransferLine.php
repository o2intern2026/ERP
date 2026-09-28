<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CHANGE_REQUESTS #167: one stock unit on a transfer (its pallet, if any, travels whole — every unit of the pallet is a line). */
class StockTransferLine extends Model
{
    protected $fillable = ['stock_transfer_id', 'stock_unit_id', 'pallet_id', 'qty', 'from_location_id'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function pallet(): BelongsTo
    {
        return $this->belongsTo(Pallet::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }
}
