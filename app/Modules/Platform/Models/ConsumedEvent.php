<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** Inbox row: (event_id, consumer) is unique, which makes every consumer idempotent (A31). */
class ConsumedEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['event_id', 'consumer', 'consumed_at'];

    protected function casts(): array
    {
        return ['consumed_at' => 'datetime'];
    }
}
