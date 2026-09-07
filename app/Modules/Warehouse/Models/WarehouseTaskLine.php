<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTaskLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'stock_unit_id', 'asn_line_id', 'order_line_id', 'location_id', 'required_qty', 'completed_qty', 'confirmed_at'];

    protected function casts(): array
    {
        return ['required_qty' => 'integer', 'completed_qty' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WarehouseTask::class, 'task_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function asnLine(): BelongsTo
    {
        return $this->belongsTo(AsnLine::class);
    }
}
