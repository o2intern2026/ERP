<?php

namespace App\Modules\Billing\Models;

use App\Modules\Platform\Models\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['invoice_id', 'charge_id', 'job_id', 'charge_code', 'description', 'qty', 'uom', 'amount_cents', 'tax_treatment', 'gst_cents'];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'amount_cents' => 'integer', 'gst_cents' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }
}
