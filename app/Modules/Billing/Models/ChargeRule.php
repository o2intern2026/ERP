<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A24: event + condition → charge code; quantity_source names the payload field (contracts/charge-codes.md). */
class ChargeRule extends Model
{
    protected $fillable = ['trigger_event', 'charge_code_id', 'condition', 'quantity_source', 'rate_match_priority', 'effective_from', 'effective_to', 'idempotency_key_template', 'active'];

    protected function casts(): array
    {
        return ['condition' => 'array', 'active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function chargeCode(): BelongsTo
    {
        return $this->belongsTo(ChargeCode::class);
    }
}
