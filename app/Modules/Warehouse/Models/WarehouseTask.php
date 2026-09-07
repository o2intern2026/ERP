<?php

namespace App\Modules\Warehouse\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Work task: execution record and billing trigger via task.completed (ERP_PLAN §4.2, §4.3 rule 8). */
class WarehouseTask extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $fillable = [
        'task_no', 'task_type', 'job_id', 'client_id', 'warehouse_id', 'source_type', 'source_id', 'order_id',
        'fulfilment_id', 'wave_id', 'asn_id', 'container_id', 'priority', 'assigned_user_id', 'status', 'exception_reason',
        'cancel_reason', 'billable_qty', 'billable_uom', 'hours_business', 'hours_after_hours', 'notes',
        'started_at', 'completed_at', 'completed_by', 'billable_event_id',
    ];

    protected function casts(): array
    {
        return [
            'billable_qty' => 'decimal:3', 'hours_business' => 'decimal:2', 'hours_after_hours' => 'decimal:2',
            'started_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseTaskLine::class, 'task_id');
    }

    public function asn(): BelongsTo
    {
        return $this->belongsTo(Asn::class);
    }

    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'assigned_user_id', 'billable_qty', 'hours_business', 'hours_after_hours'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
