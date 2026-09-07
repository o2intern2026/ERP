<?php

namespace App\Modules\Billing\Models;

use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A8b: the only way to reduce an issued invoice; needs a second person's approval (PLT-7). */
class CreditNote extends Model
{
    use BelongsToClient;

    protected $fillable = ['credit_note_no', 'invoice_id', 'job_id', 'client_id', 'reason', 'amount_cents', 'gst_cents', 'status', 'created_by', 'approved_by', 'issued_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'gst_cents' => 'integer', 'issued_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }
}
