<?php

namespace App\Modules\Billing\Models;

use App\Modules\MasterData\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A5: versioned price list. client_id null + is_standard = the standard card every new client is bound to. */
class RateCard extends Model
{
    use LogsActivity;

    protected $fillable = ['client_id', 'name', 'currency', 'version', 'effective_from', 'effective_to', 'status', 'is_standard', 'created_by', 'approved_by', 'notes'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'effective_from' => 'date', 'effective_to' => 'date', 'is_standard' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RateItem::class);
    }

    public function isEffectiveOn(\DateTimeInterface $date): bool
    {
        $d = $date->format('Y-m-d');

        return $this->status === 'active' && $this->effective_from->toDateString() <= $d && ($this->effective_to === null || $this->effective_to->toDateString() >= $d);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'version', 'effective_from', 'effective_to', 'approved_by'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
