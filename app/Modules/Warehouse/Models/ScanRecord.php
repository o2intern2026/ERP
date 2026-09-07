<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;

class ScanRecord extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_id', 'stock_unit_id', 'serial_no', 'scanned_by', 'scanned_at'];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }
}
