<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/** One outbox row per published DomainEvent (A31). status: pending | published | failed | dead (contracts/enums.md). */
class OutboxEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id', 'event_name', 'event_version', 'correlation_id', 'job_id', 'client_id', 'payload',
        'status', 'attempts', 'available_at', 'published_at', 'last_error', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'event_version' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** The envelope consumers receive (contracts/events.md "Envelope"). */
    public function envelope(): array
    {
        return [
            'event_id' => $this->event_id,
            'event_name' => $this->event_name,
            'event_version' => $this->event_version,
            'correlation_id' => $this->correlation_id,
            'job_id' => $this->job_id,
            'client_id' => $this->client_id,
            'occurred_at' => $this->created_at?->toIso8601String(),
            'payload' => $this->payload,
        ];
    }
}
