<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function variance(): int
    {
        return $this->received_cartons - $this->expected_cartons;
    }
}
