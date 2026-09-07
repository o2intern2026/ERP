<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    public $timestamps = false;

    protected $fillable = ['endpoint_id', 'event_id', 'event_name', 'status', 'attempts', 'response_code', 'last_error', 'next_attempt_at', 'delivered_at', 'created_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }
}
