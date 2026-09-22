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

    /**
     * The standard card every client is bound to (§6.3 rate_cards.is_standard): the newest active standard version, or null
     * before BillingSeeder / Finance has activated one. Used by Client::creating (CHANGE_REQUESTS #134), registration and 修复.
     */
    public static function activeStandard(): ?self
    {
        return static::query()->where('is_standard', true)->where('status', 'active')->orderByDesc('version')->first();
    }

    public static function activeStandardId(): ?int
    {
        return static::query()->where('is_standard', true)->where('status', 'active')->orderByDesc('version')->value('id');
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
