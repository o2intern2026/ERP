<?php

namespace App\Modules\Transport\Models;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\Document;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Tenancy\ClientScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/** Internal-only carrier statement header. */
class CarrierInvoice extends Model
{
    protected $fillable = [
        'carrier_id', 'invoice_no', 'period_from', 'period_to', 'total_cents', 'status', 'document_id',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('internal_invoice_visibility', function (Builder $builder): void {
            if (ClientScope::isClientRequest()) {
                $builder->whereRaw('1 = 0');
            }
        });

        static::saving(function (CarrierInvoice $invoice): void {
            if (! in_array($invoice->status, TransportEnums::CARRIER_INVOICE_STATUSES, true)) {
                throw new InvalidArgumentException("Unknown carrier invoice status: {$invoice->status}");
            }
            if ((int) $invoice->total_cents < 0) {
                throw new InvalidArgumentException('Carrier invoice total cannot be negative.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'total_cents' => 'integer',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CarrierInvoiceLine::class);
    }
}
