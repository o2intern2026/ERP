<?php

namespace App\Modules\Warehouse\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Stock unit = client + goods line + packaging unit + location (ERP_PLAN §4.2). Quantities are cartons; a pallet unit
 * holds cartons. Balances are a projection of stock_ledger maintained in the same transaction (§4.3 rule 9).
 * Reservation is a quantity, not a condition: available = qty_on_hand − qty_reserved (§4.3 rule 1).
 */
class StockUnit extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $fillable = [
        'client_id', 'job_id', 'asn_line_id', 'goods_receipt_id', 'warehouse_id', 'unit_type', 'label_code', 'location_id',
        'qty_on_hand', 'qty_reserved', 'qty_inbound', 'pallet_class', 'pallet_class_overridden_reason',
        'length_mm', 'width_mm', 'height_mm', 'weight_kg', 'pallet_source', 'condition', 'condition_reason', 'condition_changed_at', 'putaway_completed', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'integer', 'qty_reserved' => 'integer', 'qty_inbound' => 'integer',
            'weight_kg' => 'decimal:3', 'putaway_completed' => 'boolean', 'received_at' => 'datetime', 'condition_changed_at' => 'datetime',
        ];
    }

    public function asnLine(): BelongsTo
    {
        return $this->belongsTo(AsnLine::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function availableQty(): int
    {
        return max(0, $this->qty_on_hand - $this->qty_reserved);
    }

    /** Only put-away, good-condition stock can be allocated (§4.3 rules 1–2). */
    public function isAllocatable(): bool
    {
        return $this->putaway_completed && $this->condition === 'good';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['condition', 'condition_reason', 'location_id', 'pallet_class', 'pallet_source', 'warehouse_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
