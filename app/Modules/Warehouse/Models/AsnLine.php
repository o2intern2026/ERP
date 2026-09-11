<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Goods line = the identity of stock; the consignee fields feed B2c order generation (ERP_PLAN §4.2). */
class AsnLine extends Model
{
    protected $fillable = [
        'asn_id', 'container_id', 'consignment_mark', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address',
        'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference', 'description', 'package_type',
        'expected_cartons', 'received_cartons', 'damaged_cartons', 'variance_reason', 'weight_kg', 'length_mm',
        'width_mm', 'height_mm', 'cbm', 'order_line_id',
    ];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:3', 'cbm' => 'decimal:4'];
    }

    public function asn(): BelongsTo
    {
        return $this->belongsTo(Asn::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** The 入库单 line that recorded this goods line (a line is received once). */
    public function receiptLine(): HasOne
    {
        return $this->hasOne(GoodsReceiptLine::class);
    }

    /** Received (into a 入库单 or, before 入库单 existed, into stock units) — a line received with 0 cartons counts. */
    public function isReceived(): bool
    {
        return $this->relationLoaded('receiptLine') && $this->relationLoaded('stockUnits')
            ? $this->receiptLine !== null || $this->stockUnits->isNotEmpty()
            : $this->receiptLine()->exists() || $this->stockUnits()->exists();
    }

    public function variance(): int
    {
        return $this->received_cartons - $this->expected_cartons;
    }

    /** Already on a 派送订单 (B2c generation or a manual order that picked this line): the consignee fields are the order's now. */
    public function isOnOrder(): bool
    {
        return $this->order_line_id !== null;
    }

    /** Mirrors the OMS rule for 从预报单生成派送订单 (Orders AsnOrderService::completeDelivery): all five consignee fields present. */
    public function hasCompleteDelivery(): bool
    {
        return filled($this->deliver_to_name) && filled($this->deliver_to_address)
            && filled($this->deliver_to_suburb) && filled($this->deliver_to_state) && filled($this->deliver_to_postcode);
    }
}
