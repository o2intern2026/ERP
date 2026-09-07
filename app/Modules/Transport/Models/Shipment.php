<?php

namespace App\Modules\Transport\Models;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class Shipment extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'shipment_no', 'job_id', 'client_id', 'order_id', 'fulfilment_id', 'shipment_type', 'status',
        'selected_quote_id', 'carrier_id', 'service_level', 'booking_ref', 'tracking_number',
        'waybill_document_id', 'consignment_note_document_id', 'tailgate_required', 'delivery_run_id',
        'dispatched_at', 'delivered_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (Shipment $shipment): void {
            if (! in_array($shipment->shipment_type, TransportEnums::SHIPMENT_TYPES, true)) {
                throw new InvalidArgumentException("Unknown shipment type: {$shipment->shipment_type}");
            }

            if (! in_array($shipment->status, TransportEnums::shipmentStatuses($shipment->shipment_type), true)) {
                throw new InvalidArgumentException("Status {$shipment->status} is invalid for {$shipment->shipment_type}");
            }

            if ($shipment->service_level !== null && ! in_array($shipment->service_level, TransportEnums::SERVICE_LEVELS, true)) {
                throw new InvalidArgumentException("Unknown service level: {$shipment->service_level}");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'tailgate_required' => 'boolean',
            'dispatched_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(TransportQuote::class);
    }

    public function pods(): HasMany
    {
        return $this->hasMany(Pod::class);
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function carrierCost(): HasOne
    {
        return $this->hasOne(CarrierCost::class);
    }

    public function selectedQuote(): BelongsTo
    {
        return $this->belongsTo(TransportQuote::class, 'selected_quote_id');
    }

    public function deliveryRun(): BelongsTo
    {
        return $this->belongsTo(DeliveryRun::class);
    }

    public function waybillDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'waybill_document_id');
    }

    public function consignmentNoteDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'consignment_note_document_id');
    }
}
