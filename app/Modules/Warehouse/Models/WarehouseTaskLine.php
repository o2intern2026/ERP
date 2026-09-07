<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTaskLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'stock_unit_id', 'asn_line_id', 'location_id', 'required_qty', 'completed_qty', 'confirmed_at'];

    protected function casts(): array
    {
        return ['required_qty' => 'integer', 'completed_qty' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WarehouseTask::class, 'task_id');
    }
}
