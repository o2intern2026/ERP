<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'order_line_id', 'stock_unit_id', 'qty', 'status', 'created_at', 'released_at', 'released_reason'];

    protected function casts(): array
    {
        return ['qty' => 'integer', 'created_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
