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
        // 到仓方式 (CHANGE_REQUESTS #124): the collection request and what Transport reported back about its shipment.
        'inbound_transport', 'collection_address', 'collection_ready_date', 'collection_packages', 'collection_notes', 'collection_requested_at',
        'collection_requested_by', 'collection_version', 'collection_shipment_id', 'collection_status', 'collection_plan',
    ];

    /** collection_status values after which the request is Transport's: edits and 改为客户自送 go through the dispatcher (#124). */
    public const COLLECTION_LOCKED_STATUSES = ['booked', 'collected', 'delivered'];

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
            'collection_address' => 'array',
            'collection_ready_date' => 'date',
            'collection_packages' => 'array',
            'collection_requested_at' => 'datetime',
            'collection_plan' => 'array',
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

    /** 我方上门提货: Transport collects the goods at the client's pickup address and brings them here (CHANGE_REQUESTS #124). */
    public function isCollection(): bool
    {
        return $this->inbound_transport === 'we_collect';
    }

    /** Once the collection is booked (or further), the request belongs to Transport — the ASN page no longer edits or cancels it. */
    public function collectionLocked(): bool
    {
        return in_array($this->collection_status, self::COLLECTION_LOCKED_STATUSES, true);
    }

    public function collectionRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collection_requested_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'expected_date', 'unplanned_confirmed', 'warehouse_id', 'inbound_transport', 'collection_status'])->logOnlyDirty()->dontSubmitEmptyLogs();
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
