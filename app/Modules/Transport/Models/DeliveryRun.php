<?php

namespace App\Modules\Transport\Models;

use App\Models\User;
use App\Modules\Transport\Support\TransportEnums;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class DeliveryRun extends Model
{
    protected $fillable = ['run_no', 'run_date', 'driver_id', 'vehicle', 'status'];

    protected static function booted(): void
    {
        static::saving(function (DeliveryRun $run): void {
            if (! in_array($run->status, TransportEnums::DELIVERY_RUN_STATUSES, true)) {
                throw new InvalidArgumentException("Unknown delivery run status: {$run->status}");
            }
        });
    }

    protected function casts(): array
    {
        return ['run_date' => 'immutable_date'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RunStop::class)->orderBy('seq');
    }
}
