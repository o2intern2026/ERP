<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Row of the shared `exceptions` table (A28); holds are rows with type = hold. Write only through ExceptionService. */
class ExceptionRecord extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $table = 'exceptions';

    protected $fillable = [
        'type', 'source_module', 'status', 'job_id', 'client_id', 'order_id', 'source_type', 'source_id',
        'hold_type', 'message', 'owner_id', 'created_by', 'resolved_by', 'resolved_at',
        'released_by', 'released_at', 'release_reason',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isHold(): bool
    {
        return $this->type === 'hold';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'owner_id', 'resolved_by', 'release_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
