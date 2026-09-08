<?php

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one receiving batch recorded for one ASN goods line (snapshot; the live figures stay on asn_lines). */
class GoodsReceiptLine extends Model
{
    protected $fillable = [
        'goods_receipt_id', 'asn_line_id', 'expected_cartons', 'received_cartons', 'damaged_cartons', 'variance_reason',
        'unit_count', 'pallet_count', 'received_at', 'received_by',
    ];

    protected function casts(): array
    {
        return [
            'expected_cartons' => 'integer', 'received_cartons' => 'integer', 'damaged_cartons' => 'integer',
            'unit_count' => 'integer', 'pallet_count' => 'integer', 'received_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function asnLine(): BelongsTo
    {
        return $this->belongsTo(AsnLine::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Signed difference: received + damaged − expected (damaged cartons did arrive). */
    public function variance(): int
    {
        return $this->received_cartons + $this->damaged_cartons - $this->expected_cartons;
    }
}
