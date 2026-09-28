<?php

namespace App\Modules\Warehouse\Models;

use App\Modules\MasterData\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CHANGE_REQUESTS #167 调拨单: pallets / units of one client + one Job moving from one warehouse to another.
 * draft → dispatched (goods in the origin's transit location, not allocatable) → received (at the destination dock, then the ordinary
 * putaway) | cancelled (draft only). `charge_to`: internal (our own reason — nothing billed, billing warehouse unchanged) |
 * client (the client asked — dispatch / receive / transport charges per pallet, billing warehouse follows the goods on receipt).
 */
class StockTransfer extends Model
{
    public const STATUSES = ['draft', 'dispatched', 'received', 'cancelled'];

    public const CHARGE_TO = ['internal', 'client'];

    protected $fillable = [
        'transfer_no', 'client_id', 'job_id', 'from_warehouse_id', 'to_warehouse_id', 'charge_to', 'status', 'vehicle', 'driver_name', 'notes',
        'created_by', 'dispatched_by', 'dispatched_at', 'received_by', 'received_at', 'receiving_location_id',
    ];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function isClientRequested(): bool
    {
        return $this->charge_to === 'client';
    }
}
