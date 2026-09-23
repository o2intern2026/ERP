<?php

namespace App\Modules\MasterData\Models;

use App\Modules\Billing\Models\RateCard;
use App\Support\Enums;
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
        'import_defaults', // CHANGE_REQUESTS #145
    ];

    /** CHANGE_REQUESTS #145 自动导入: today's rules and nothing automated — what a client without stored defaults gets. */
    public const IMPORT_DEFAULTS = ['group_by' => 'mark', 'address_type_default' => 'auto', 'auto_confirm' => false, 'inbox_enabled' => false, 'notify_email' => null];

    protected function casts(): array
    {
        return ['default_markup_percent' => 'decimal:2', 'import_defaults' => 'array'];
    }

    /**
     * CHANGE_REQUESTS #145: what an automated list of this client (API push, inbox folder) is read with — the stored defaults over
     * IMPORT_DEFAULTS, every value validated again so a hand-edited row never changes how a list is grouped.
     *
     * @return array{group_by:string, address_type_default:string, auto_confirm:bool, inbox_enabled:bool, notify_email:?string}
     */
    public function importDefaults(): array
    {
        return self::importDefaultsFrom(is_array($this->import_defaults) ? $this->import_defaults : []);
    }

    /**
     * The stored shape of `clients.import_defaults` from a form or a raw array: an unknown option falls back to today's rule, the two
     * flags become booleans (an unticked checkbox is simply absent), the email is trimmed or null.
     *
     * @param  array<string, mixed>  $raw
     * @return array{group_by:string, address_type_default:string, auto_confirm:bool, inbox_enabled:bool, notify_email:?string}
     */
    public static function importDefaultsFrom(array $raw): array
    {
        $email = trim((string) ($raw['notify_email'] ?? ''));

        return [
            'group_by' => in_array($raw['group_by'] ?? null, Enums::IMPORT_GROUP_BYS, true) ? $raw['group_by'] : self::IMPORT_DEFAULTS['group_by'],
            'address_type_default' => in_array($raw['address_type_default'] ?? null, Enums::IMPORT_ADDRESS_TYPE_DEFAULTS, true) ? $raw['address_type_default'] : self::IMPORT_DEFAULTS['address_type_default'],
            'auto_confirm' => filter_var($raw['auto_confirm'] ?? false, FILTER_VALIDATE_BOOL),
            'inbox_enabled' => filter_var($raw['inbox_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'notify_email' => $email === '' ? null : mb_substr($email, 0, 255),
        ];
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
