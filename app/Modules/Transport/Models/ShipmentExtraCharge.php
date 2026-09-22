<?php

namespace App\Modules\Transport\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CHANGE_REQUESTS #135 (audit TMS-11): the Transport-side record of one 报告配送额外费用, written with its `delivery.extra_charge` event. */
class ShipmentExtraCharge extends Model
{
    protected $fillable = [
        'shipment_id', 'charge_type', 'qty', 'uom', 'cost_cents', 'note', 'reported_by', 'reported_at', 'event_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'cost_cents' => 'integer',
            'reported_at' => 'immutable_datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
