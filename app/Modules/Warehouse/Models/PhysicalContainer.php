<?php

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * 物理柜 (CHANGE_REQUESTS #122): the one physical box that N container rows of N ASNs / clients share (拼柜 / LCL) — or a single
 * client's box when the coordinator chooses to register it (FCL). Spans clients, so no BelongsToClient: staff screens only,
 * never loaded from a portal route. Basic fields only — no seals, customs or CFS lifecycle (ERP_PLAN §4.1.2).
 */
class PhysicalContainer extends Model
{
    use LogsActivity;

    protected $fillable = [
        'container_no', 'warehouse_id', 'size', 'unpack_mode', 'gross_weight_kg', 'consolidation', 'allocation_basis', 'cartage_by_us',
        'sideloader_required', 'eta_date', 'arrived_at', 'devanned_at', 'status', 'devanning_task_id', 'allocation_version', 'allocation_stale',
        'arrived_event_id', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'gross_weight_kg' => 'decimal:3', 'cartage_by_us' => 'boolean', 'sideloader_required' => 'boolean', 'eta_date' => 'date',
            'arrived_at' => 'datetime', 'devanned_at' => 'datetime', 'allocation_version' => 'integer', 'allocation_stale' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** The container rows (one per ASN) that sit in this box. */
    public function members(): HasMany
    {
        return $this->hasMany(Container::class)->orderBy('id');
    }

    /** The ONE box-level devanning task (source_type physical_container). */
    public function devanningTask(): BelongsTo
    {
        return $this->belongsTo(WarehouseTask::class, 'devanning_task_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isLcl(): bool
    {
        return $this->consolidation === 'lcl';
    }

    /** The devanning fee has been allocated (task done) — members can no longer be unlinked, only re-split (重算分摊). */
    public function isDevanned(): bool
    {
        return $this->devanned_at !== null;
    }

    /** Something (devanning or arrival) has been emitted under the current members: a later link / unlink needs 重算分摊. */
    public function hasEmitted(): bool
    {
        return $this->allocation_version > 0;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'consolidation', 'allocation_basis', 'cartage_by_us', 'sideloader_required', 'gross_weight_kg', 'allocation_version'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
