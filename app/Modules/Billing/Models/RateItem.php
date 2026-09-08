<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A5: one priced line of a rate card; thresholds and bands are data on the row, never in code. Immutable once the card is active. */
class RateItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'rate_card_id', 'charge_code_id', 'pallet_class', 'threshold_json', 'weight_band_min', 'weight_band_max', 'zone',
        'pricing_mode', 'carrier_id', 'service_level', 'markup_percent', 'rate_cents', 'min_charge_cents', 'is_poa', 'notes',
    ];

    protected function casts(): array
    {
        return ['threshold_json' => 'array', 'weight_band_min' => 'decimal:2', 'weight_band_max' => 'decimal:2', 'markup_percent' => 'decimal:2', 'rate_cents' => 'integer', 'min_charge_cents' => 'integer', 'is_poa' => 'boolean'];
    }

    /** §2.5 #4: who changed which rate of which card, when. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['rate_card_id', 'charge_code_id', 'pricing_mode', 'rate_cents', 'min_charge_cents', 'markup_percent', 'is_poa', 'threshold_json', 'weight_band_min', 'weight_band_max', 'zone', 'notes'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('rate_card');
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    public function chargeCode(): BelongsTo
    {
        return $this->belongsTo(ChargeCode::class);
    }

    public function threshold(string $key, mixed $default = null): mixed
    {
        return $this->threshold_json[$key] ?? $default;
    }
}
