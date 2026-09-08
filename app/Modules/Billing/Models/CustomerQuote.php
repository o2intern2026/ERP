<?php

namespace App\Modules\Billing\Models;

use App\Modules\MasterData\Models\Client;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A18 / FIN-6: a customer quote (preliminary or final) built from charge codes; X1's A7b shares it. */
class CustomerQuote extends Model
{
    use BelongsToClient;

    protected $fillable = ['quote_no', 'job_id', 'client_id', 'order_id', 'stage', 'valid_until', 'status', 'subtotal_cents', 'gst_cents', 'total_cents', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'subtotal_cents' => 'integer', 'gst_cents' => 'integer', 'total_cents' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerQuoteLine::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
