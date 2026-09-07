<?php

namespace App\Modules\Platform\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

/** Row of the shared `exceptions` table (A28); holds are rows with type = hold. Write only through ExceptionService. */
class ExceptionRecord extends Model
{
    use BelongsToClient;

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

    public function isHold(): bool
    {
        return $this->type === 'hold';
    }
}
