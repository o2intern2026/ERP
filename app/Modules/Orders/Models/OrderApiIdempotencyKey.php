<?php

namespace App\Modules\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A4b: Idempotency-Key → order, per client; a replayed request returns the original order instead of a duplicate. */
class OrderApiIdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['client_id', 'idempotency_key', 'order_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
