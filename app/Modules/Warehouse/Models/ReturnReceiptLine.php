<?php

namespace App\Modules\Warehouse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnReceiptLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['return_receipt_id', 'original_order_line_id', 'asn_line_id', 'original_fulfilment_id', 'description', 'expected_qty', 'received_qty', 'condition', 'disposition', 'stock_unit_id', 'received_at', 'inspected_at'];

    protected function casts(): array
    {
        return ['expected_qty' => 'integer', 'received_qty' => 'integer', 'received_at' => 'datetime', 'inspected_at' => 'datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ReturnReceipt::class, 'return_receipt_id');
    }

    public function stockUnit(): BelongsTo
    {
        return $this->belongsTo(StockUnit::class);
    }
}
