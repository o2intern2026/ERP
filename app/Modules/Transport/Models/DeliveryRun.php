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

    /** CHANGE_REQUESTS #133: planned or dispatched — a pending stop may still leave the run. */
    public function isOpen(): bool
    {
        return in_array($this->status, ['planned', 'dispatched'], true);
    }

    /** CHANGE_REQUESTS #133: open and no stop delivered yet — date / driver / vehicle may change and the run may be cancelled. */
    public function isEditable(): bool
    {
        return $this->isOpen() && ! $this->stops()->where('status', 'delivered')->exists();
    }
}
