<?php

namespace App\Modules\Transport\Models;

use App\Support\Tenancy\ClientScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Internal-only per-shipment statement comparison. */
class CarrierInvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'carrier_invoice_id', 'shipment_id', 'tracking_number', 'billed_cents', 'expected_cents',
        'variance_cents', 'matched', 'note',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('internal_invoice_line_visibility', function (Builder $builder): void {
            if (ClientScope::isClientRequest()) {
                $builder->whereRaw('1 = 0');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'billed_cents' => 'integer',
            'expected_cents' => 'integer',
            'variance_cents' => 'integer',
            'matched' => 'boolean',
        ];
    }

    public function carrierInvoice(): BelongsTo
    {
        return $this->belongsTo(CarrierInvoice::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
