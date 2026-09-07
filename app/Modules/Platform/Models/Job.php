<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Business Job (ERP_PLAN §1.6). Create only through JobService; statuses are derived, never edited by hand. */
class Job extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $fillable = [
        'job_no', 'client_id', 'job_type', 'operational_status', 'revenue_status', 'cost_status',
        'estimated_revenue_cents', 'actual_revenue_cents', 'estimated_cost_cents', 'actual_cost_cents',
        'reference', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_revenue_cents' => 'integer',
            'actual_revenue_cents' => 'integer',
            'estimated_cost_cents' => 'integer',
            'actual_cost_cents' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
