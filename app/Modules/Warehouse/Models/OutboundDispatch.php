<?php

namespace App\Modules\Warehouse\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** B4 handover: the moment goods left the warehouse (dispatched), distinct from packed (§4.3 rule 5). */
class OutboundDispatch extends Model
{
    use BelongsToClient;

    protected $fillable = ['fulfilment_id', 'order_id', 'job_id', 'client_id', 'warehouse_id', 'pallet_count', 'package_count', 'handed_to', 'shipment_id', 'dispatched_by', 'dispatched_at'];

    protected function casts(): array
    {
        return ['pallet_count' => 'integer', 'package_count' => 'integer', 'dispatched_at' => 'datetime'];
    }
}
