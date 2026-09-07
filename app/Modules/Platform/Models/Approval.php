<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A19: a request for a second person's approval (PLT-7). Write only through ApprovalService. */
class Approval extends Model
{
    use BelongsToClient, LogsActivity;

    protected $fillable = ['type', 'subject_type', 'subject_id', 'client_id', 'job_id', 'requested_by', 'request_note', 'payload', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'decided_by', 'decision_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
