<?php

namespace App\Modules\Platform\Models;

use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Unified document store (A29). Write only through DocumentService; portal visibility = client_visible. */
class Document extends Model
{
    use BelongsToClient;
    use LogsActivity;

    protected $fillable = [
        'type', 'related_type', 'related_id', 'job_id', 'client_id', 'client_visible',
        'storage_path', 'original_name', 'mime', 'size_bytes', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['client_visible' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['type', 'client_visible', 'related_type', 'related_id'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
