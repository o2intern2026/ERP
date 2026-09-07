<?php

namespace App\Modules\Transport\Models;

use App\Modules\Transport\Support\TransportEnums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class TrackingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shipment_id', 'status', 'description', 'location', 'source', 'occurred_at', 'raw', 'created_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (TrackingEvent $event): void {
            if (! in_array($event->source, TransportEnums::TRACKING_SOURCES, true)) {
                throw new InvalidArgumentException("Unknown tracking source: {$event->source}");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'raw' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
