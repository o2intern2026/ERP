<?php

namespace App\Modules\Orders\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only audit timeline for all three order status dimensions. */
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['order_id', 'dimension', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order events are append-only.'));
        static::deleting(fn () => throw new LogicException('Order events are append-only.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
