<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A10: a receipt against an invoice; the platform is not a ledger (FIN-8). */
class Payment extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'amount_cents', 'paid_at', 'method', 'reference', 'recorded_by', 'created_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'date', 'created_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
