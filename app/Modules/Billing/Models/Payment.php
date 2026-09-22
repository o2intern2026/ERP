<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A10: a receipt against an invoice; the platform is not a ledger (FIN-8). Never deleted: 作废收款 (audit 2026-09-22 FIN-13,
 * CHANGE_REQUESTS #140) writes an offsetting negative row whose `void_of_payment_id` points at the payment it voids.
 */
class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'amount_cents', 'paid_at', 'method', 'reference', 'note', 'recorded_by', 'void_of_payment_id', 'created_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'date', 'created_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The payment this negative row voids (null on an ordinary receipt). */
    public function voidOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'void_of_payment_id');
    }

    /** The negative row that voided this receipt, once 作废收款 was recorded. */
    public function voidedBy(): HasOne
    {
        return $this->hasOne(self::class, 'void_of_payment_id');
    }

    public function isVoidRow(): bool
    {
        return $this->void_of_payment_id !== null;
    }
}
