<?php

namespace App\Modules\Transport\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Support\TransportEnums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class TransportQuote extends Model
{
    protected $fillable = [
        'shipment_id', 'carrier_id', 'source', 'service_level', 'cost_cents', 'customer_price_cents',
        'markup_percent', 'eta_days', 'is_recommended', 'is_cheapest', 'is_fastest', 'quote_stage',
        'status', 'selected_by', 'selected_by_user_id', 'quoted_at', 'expires_at', 'raw_response',
    ];

    protected static function booted(): void
    {
        static::saving(function (TransportQuote $quote): void {
            $values = [
                [$quote->source, TransportEnums::SOURCES, 'source'],
                [$quote->service_level, TransportEnums::SERVICE_LEVELS, 'service level'],
                [$quote->quote_stage, TransportEnums::QUOTE_STAGES, 'quote stage'],
                [$quote->status, TransportEnums::QUOTE_STATUSES, 'quote status'],
            ];

            foreach ($values as [$value, $allowed, $label]) {
                if (! in_array($value, $allowed, true)) {
                    throw new InvalidArgumentException("Unknown {$label}: {$value}");
                }
            }

            if ($quote->selected_by !== null && ! in_array($quote->selected_by, TransportEnums::SELECTED_BY, true)) {
                throw new InvalidArgumentException("Unknown quote selector: {$quote->selected_by}");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'cost_cents' => 'integer',
            'customer_price_cents' => 'integer',
            'markup_percent' => 'decimal:2',
            'eta_days' => 'integer',
            'is_recommended' => 'boolean',
            'is_cheapest' => 'boolean',
            'is_fastest' => 'boolean',
            'quoted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'raw_response' => 'array',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function selectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by_user_id');
    }
}
