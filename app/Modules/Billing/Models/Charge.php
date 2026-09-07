<?php

namespace App\Modules\Billing\Models;

use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Tenancy\BelongsToClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A6a: a fee line with its rate and calculation snapshot. Never deleted — corrections are reversals (§6.3). */
class Charge extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'job_id', 'client_id', 'charge_date', 'charge_code_id', 'rate_card_id', 'rate_card_version', 'rate_item_id', 'uom', 'qty',
        'rate_snapshot_cents', 'amount_cents', 'calculation_snapshot_json', 'tax_treatment', 'status', 'source_type', 'source_id',
        'source_activity_id', 'activity_version', 'reversal_of_charge_id', 'is_manual', 'manual_reason', 'created_by', 'invoice_line_id',
    ];

    protected function casts(): array
    {
        return ['charge_date' => 'date', 'qty' => 'decimal:3', 'rate_snapshot_cents' => 'integer', 'amount_cents' => 'integer', 'calculation_snapshot_json' => 'array', 'activity_version' => 'integer', 'is_manual' => 'boolean'];
    }

    public function chargeCode(): BelongsTo
    {
        return $this->belongsTo(ChargeCode::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rateItem(): BelongsTo
    {
        return $this->belongsTo(RateItem::class);
    }

    public function isBillable(): bool
    {
        return in_array($this->status, ['pending', 'approved'], true);
    }

    public function gstCents(): int
    {
        return $this->tax_treatment === 'gst_10' ? (int) round($this->amount_cents * 0.10) : 0;
    }
}
