<?php

namespace App\Modules\Transport\Models;

use App\Modules\Transport\Support\TransportEnums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class RunStop extends Model
{
    protected $fillable = ['delivery_run_id', 'shipment_id', 'seq', 'eta', 'arrived_at', 'status'];

    protected static function booted(): void
    {
        static::saving(function (RunStop $stop): void {
            if (! in_array($stop->status, TransportEnums::RUN_STOP_STATUSES, true)) {
                throw new InvalidArgumentException("Unknown run stop status: {$stop->status}");
            }

            if ((int) $stop->seq < 1) {
                throw new InvalidArgumentException('Run stop sequence must be positive.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'eta' => 'immutable_datetime',
            'arrived_at' => 'immutable_datetime',
        ];
    }

    public function deliveryRun(): BelongsTo
    {
        return $this->belongsTo(DeliveryRun::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
