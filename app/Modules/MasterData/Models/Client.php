<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Billing\Models\RateCard;
use App\Support\Tenancy\ClientScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use LogsActivity;

    public const PAYMENT_TERMS_PATTERN = '/^(prepaid|eom|net_\d{1,3})$/';

    protected $fillable = [
        'code', 'name', 'abn', 'leg_type', 'contact_name', 'contact_phone', 'contact_email', 'billing_email',
        'address', 'suburb', 'state', 'postcode', 'status', 'payment_terms', 'invoice_mode',
        'default_markup_percent', 'dispatch_cutoff_time', 'standard_rate_card_id', 'invoice_period', 'invoice_grouping',
    ];

    protected function casts(): array
    {
        return ['default_markup_percent' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        // A client-role user sees only their own client record (multi-tenancy in the data layer, ERP_PLAN §0.2).
        static::addGlobalScope('own_client', function (Builder $query) {
            $clientId = ClientScope::currentClientId();
            if ($clientId !== null) {
                $query->whereKey($clientId);
            }
        });

        // Audit A13 / GAP-01, CHANGE_REQUESTS #134: every new client is bound to the active standard rate card, whichever code
        // path creates it (self-registration, seeders, tests). Null stays null only while no standard card is active yet —
        // BillingSeeder back-fills those, and the clients pages offer 修复 (bindStandardCard) for any that slip through.
        static::creating(function (Client $client) {
            if ($client->standard_rate_card_id === null) {
                $client->standard_rate_card_id = RateCard::activeStandardId();
            }
        });
    }

    /** The standard card this client is billed on when no client-specific card prices a code (§0.2 rate lookup order). */
    public function standardRateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'standard_rate_card_id');
    }

    /** The client's active 专属价目表, if Finance has created and activated one (RateService looks it up first). */
    public function activeOwnRateCard(): ?RateCard
    {
        return RateCard::query()->where('client_id', $this->id)->where('status', 'active')->orderByDesc('version')->first();
    }

    /**
     * Invoice due date from payment_terms (ERP_PLAN §6.3 invoices.due_at): prepaid = on issue, eom = end of the
     * issue month, net_N = N days after issue. Never blocks booking or dispatch (§0.2 rule 9).
     */
    public function dueDateFor(CarbonInterface $issuedAt): CarbonInterface
    {
        return match (true) {
            $this->payment_terms === 'prepaid' => $issuedAt->copy(),
            $this->payment_terms === 'eom' => $issuedAt->copy()->endOfMonth(),
            (bool) preg_match('/^net_(\d{1,3})$/', $this->payment_terms, $m) => $issuedAt->copy()->addDays((int) $m[1]),
            default => throw new InvalidArgumentException("Unknown payment_terms: {$this->payment_terms}"),
        };
    }

    public function billsPerJob(): bool
    {
        return $this->invoice_mode === 'per_job';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
