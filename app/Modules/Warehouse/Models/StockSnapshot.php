<?php

namespace App\Modules\Warehouse\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** One row per stock unit per day; Billing derives weekly storage, pallet rental and pickface fees from it (§4.8). */
class StockSnapshot extends Model
{
    use BelongsToClient;

    public $timestamps = false;

    protected $fillable = [
        'snapshot_date', 'warehouse_id', 'client_id', 'job_id', 'stock_unit_id', 'asn_line_id', 'unit_type', 'pallet_class',
        'pallet_source', 'location_id', 'location_type', 'condition', 'qty_on_hand', 'qty_reserved', 'created_at',
    ];

    protected function casts(): array
    {
        return ['snapshot_date' => 'date', 'qty_on_hand' => 'integer', 'qty_reserved' => 'integer', 'created_at' => 'datetime'];
    }
}
