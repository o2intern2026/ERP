<?php

namespace App\Modules\Transport\Models;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\Job;
use App\Support\Tenancy\ClientScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/** Internal-only carrier cost. Client-role requests cannot query this model. */
class CarrierCost extends Model
{
    protected $fillable = [
        'shipment_id', 'job_id', 'carrier_id', 'expected_cost_cents', 'actual_cost_cents',
        'variance_cents', 'note', 'confirmed_at',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('internal_cost_visibility', function (Builder $builder): void {
            if (ClientScope::isClientRequest()) {
                $builder->whereRaw('1 = 0');
            }
        });

        static::saving(function (CarrierCost $cost): void {
            if ((int) $cost->expected_cost_cents < 0
                || ($cost->actual_cost_cents !== null && (int) $cost->actual_cost_cents < 0)) {
                throw new InvalidArgumentException('Carrier costs cannot be negative.');
            }

            if ($cost->actual_cost_cents === null) {
                $cost->variance_cents = null;
                $cost->confirmed_at = null;

                return;
            }

            $cost->variance_cents = (int) $cost->actual_cost_cents - (int) $cost->expected_cost_cents;
            $cost->confirmed_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'expected_cost_cents' => 'integer',
            'actual_cost_cents' => 'integer',
            'variance_cents' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
