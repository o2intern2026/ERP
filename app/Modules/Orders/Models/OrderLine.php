<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLine extends Model
{
    protected $fillable = [
        'order_id', 'description_cn', 'description_en', 'hs_code', 'material', 'usage', 'brand',
        'package_type', 'carton_qty', 'unit_qty', 'unit_price_cents', 'total_price_cents',
        'actual_weight_kg', 'length_mm', 'width_mm', 'height_mm', 'cbm', 'qty_shipped',
        'qty_backordered', 'asn_line_id', 'stock_unit_ref',
    ];

    protected function casts(): array
    {
        return [
            'carton_qty' => 'integer',
            'unit_qty' => 'integer',
            'unit_price_cents' => 'integer',
            'total_price_cents' => 'integer',
            'actual_weight_kg' => 'decimal:3',
            'length_mm' => 'integer',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'cbm' => 'decimal:4',
            'qty_shipped' => 'integer',
            'qty_backordered' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfilmentLines(): HasMany
    {
        return $this->hasMany(FulfilmentLine::class);
    }
}
