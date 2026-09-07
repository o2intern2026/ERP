<?php

namespace App\Modules\Warehouse\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** B4: a packed carton / pallet with its label; dimensions and weight feed TMS quoting and the tailgate rule. */
class Package extends Model
{
    use BelongsToClient;

    protected $fillable = ['fulfilment_id', 'order_id', 'job_id', 'client_id', 'package_type', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'carton_label'];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:3'];
    }
}
