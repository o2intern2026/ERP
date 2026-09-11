<?php

namespace App\Modules\Portal\Models;

use App\Modules\Warehouse\Models\Asn;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A client's self-service ASN submission (CHANGE_REQUESTS #116): channel portal | api, optional Idempotency-Key for API replays. */
class PortalAsnSubmission extends Model
{
    use BelongsToClient;

    public $timestamps = false;

    protected $fillable = ['client_id', 'asn_id', 'channel', 'idempotency_key', 'token_id', 'user_id', 'line_count', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function asn(): BelongsTo
    {
        return $this->belongsTo(Asn::class);
    }
}
