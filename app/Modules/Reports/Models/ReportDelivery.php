<?php

namespace App\Modules\Reports\Models;

use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A22: the send log of scheduled client reports (Reports / X1 table). */
class ReportDelivery extends Model
{
    use BelongsToClient;

    public const STATUSES = ['sent', 'skipped', 'failed'];

    protected $fillable = ['client_id', 'frequency', 'period_start', 'period_end', 'email', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'sent_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
