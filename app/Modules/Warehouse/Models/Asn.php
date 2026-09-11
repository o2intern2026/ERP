<?php

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Inbound master document (ERP_PLAN §4.2): booked → arrived → receiving → putaway → closed. */
class Asn extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $fillable = [
        'asn_no', 'job_id', 'client_id', 'warehouse_id', 'expected_date', 'inbound_type', 'status', 'created_by_type',
        'created_by', 'unplanned', 'unplanned_confirmed', 'client_confirmed_at', 'client_confirmed_by', 'arrived_at', 'receiving_completed_at', 'putaway_completed_at', 'closed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date',
            'unplanned' => 'boolean',
            'unplanned_confirmed' => 'boolean',
            'client_confirmed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'receiving_completed_at' => 'datetime',
            'putaway_completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function containers(): HasMany
    {
        return $this->hasMany(Container::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AsnLine::class);
    }

    /** 入库单 batches of this ASN (预报单), ordered by batch_no. */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('batch_no');
    }

    public function isContainer(): bool
    {
        return $this->inbound_type === 'container';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'expected_date', 'unplanned_confirmed', 'warehouse_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clientConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_confirmed_by');
    }

    /** Submitted by the client itself (portal form or API push, CHANGE_REQUESTS #116) rather than keyed in by staff. */
    public function isClientSubmitted(): bool
    {
        return $this->created_by_type === 'client';
    }

    /** A client submission customer service has not confirmed yet — flagged on every screen, never a gate on warehouse work. */
    public function isPendingClientConfirmation(): bool
    {
        return $this->isClientSubmitted() && $this->client_confirmed_at === null;
    }
}
