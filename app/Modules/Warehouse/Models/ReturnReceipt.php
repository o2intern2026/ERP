<?php

namespace App\Modules\Warehouse\Models;

use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** B13: returned goods are received, inspected, then restocked / quarantined / disposed (§4.3 rule 7). */
class ReturnReceipt extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'receipt_no', 'job_id', 'client_id', 'return_order_id', 'original_order_id', 'original_shipment_id', 'return_shipment_id',
        'warehouse_id', 'status', 'received_at', 'inspected_at', 'inspected_by', 'completed_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'inspected_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnReceiptLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
