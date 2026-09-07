<?php

namespace App\Modules\Transport\Models;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Support\TransportEnums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class CarrierService extends Model
{
    protected $fillable = [
        'carrier_id', 'source', 'service_level', 'default_eta_days', 'active', 'config',
    ];

    protected static function booted(): void
    {
        static::saving(function (CarrierService $service): void {
            if (! in_array($service->source, TransportEnums::SOURCES, true)) {
                throw new InvalidArgumentException("Unknown carrier source: {$service->source}");
            }

            if (! in_array($service->service_level, TransportEnums::SERVICE_LEVELS, true)) {
                throw new InvalidArgumentException("Unknown service level: {$service->service_level}");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'default_eta_days' => 'integer',
            'active' => 'boolean',
            'config' => 'array',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
