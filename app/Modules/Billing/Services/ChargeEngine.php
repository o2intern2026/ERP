<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\ChargeRule;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\RateService;
use Illuminate\Support\Facades\DB;

/**
 * A6a: Operational event → matching charge rules → charge code → rate item → charge (with snapshots and a business
 * idempotency key). Missing rate → exception, never $0. POA → needs_review. A newer activity_version reverses the
 * older charges (cancel / redo produce reversals, never deletions). ERP_PLAN §6.4.
 */
final class ChargeEngine
{
    public function __construct(private readonly RateService $rates, private readonly ExceptionService $exceptions) {}

    /**
     * @param  array<string, mixed>  $envelope  outbox envelope (event_name, job_id, client_id, payload)
     * @return list<Charge>
     */
    public function applyEvent(array $envelope): array
    {
        $eventName = $envelope['event_name'];
        $payload = $envelope['payload'];
        $clientId = (int) ($payload['client_id'] ?? $envelope['client_id']);
        $jobId = (int) ($payload['job_id'] ?? $envelope['job_id']);
        $version = (int) ($payload['activity_version'] ?? 1);
        $context = $this->context($payload);

        $rules = ChargeRule::query()->with('chargeCode')->where('trigger_event', $eventName)->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', today()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', today()))
            ->get();

        $charges = [];
        foreach ($rules as $rule) {
            if (! $this->matches($rule->condition ?? [], $context)) {
                continue;
            }
            foreach ($this->quantities($rule, $payload, $clientId, $eventName) as [$qty, $extraContext]) {
                if ($qty <= 0) {
                    continue;
                }
                $activityId = $this->render($rule->idempotency_key_template, $payload + ['client_id' => $clientId]);
                $charge = $this->charge($rule->chargeCode, $clientId, $jobId, $qty, $context + $extraContext, $activityId, $version, $this->source($eventName, $payload));
                if ($charge !== null) {
                    $charges[] = $charge;
                }
            }
        }

        return $charges;
    }

    /**
     * Price and record one charge under its idempotency key. Same key + version → returns the existing charge;
     * higher version → reverses the older ones first.
     */
    public function charge(ChargeCode $code, int $clientId, int $jobId, float $qty, array $context, string $activityId, int $version, array $source, ?\DateTimeInterface $at = null): ?Charge
    {
        return DB::transaction(function () use ($code, $clientId, $jobId, $qty, $context, $activityId, $version, $source, $at): ?Charge {
            $existing = Charge::query()->withoutGlobalScopes()->where('source_activity_id', $activityId)->where('charge_code_id', $code->id)->where('activity_version', $version)->first();
            if ($existing !== null) {
                return $existing;
            }

            $older = Charge::query()->withoutGlobalScopes()->where('source_activity_id', $activityId)->where('charge_code_id', $code->id)
                ->where('activity_version', '<', $version)->where('status', '!=', 'reversed')->whereNull('reversal_of_charge_id')->get();
            foreach ($older as $old) {
                $this->reverse($old, "superseded by activity version {$version}");
            }

            $priced = $this->rates->price($clientId, $code->code, $qty, $context, $at);

            if ($priced['missing_rate']) {
                if ($this->codeHasBandedItems($clientId, $code)) {
                    return null; // a banded code (carton weight, zone, carrier) simply did not match this line — not a missing rate
                }
                $this->exceptions->raise('missing_rate', 'billing', [
                    'job_id' => Job::query()->whereKey($jobId)->exists() ? $jobId : null, 'client_id' => $clientId, 'source_type' => $source['type'], 'source_id' => $source['id'],
                    'message' => "No rate for {$code->code} (qty {$qty}) — client card and standard card both lack it",
                ]);

                return null;
            }

            return Charge::query()->create([
                'job_id' => $jobId,
                'client_id' => $clientId,
                'charge_date' => ($at ?? now())->format('Y-m-d'),
                'charge_code_id' => $code->id,
                'rate_card_id' => $priced['rate_card_id'],
                'rate_card_version' => $priced['rate_card_version'],
                'rate_item_id' => $priced['rate_item_id'],
                'uom' => $priced['uom'] ?? $code->default_uom,
                'qty' => $priced['qty'],
                'rate_snapshot_cents' => $priced['rate_cents'],
                'amount_cents' => $priced['amount_cents'],
                'calculation_snapshot_json' => $priced['calculation_snapshot'],
                'tax_treatment' => $code->tax_treatment,
                'status' => $priced['is_poa'] ? 'needs_review' : 'pending',
                'source_type' => $source['type'],
                'source_id' => $source['id'],
                'source_activity_id' => $activityId,
                'activity_version' => $version,
            ]);
        });
    }

    /** A8b manual one-off charge: reason required; priced from the card unless an amount is given (FIN-5). */
    public function manual(int $jobId, int $clientId, string $chargeCode, float $qty, string $reason, ?int $amountCents = null, ?int $createdBy = null): Charge
    {
        $code = ChargeCode::query()->where('code', $chargeCode)->firstOrFail();
        $priced = $this->rates->price($clientId, $code->code, $qty);
        $amount = $amountCents ?? $priced['amount_cents'];

        return Charge::query()->create([
            'job_id' => $jobId, 'client_id' => $clientId, 'charge_date' => today(), 'charge_code_id' => $code->id,
            'rate_card_id' => $priced['rate_card_id'], 'rate_card_version' => $priced['rate_card_version'], 'rate_item_id' => $priced['rate_item_id'],
            'uom' => $priced['uom'] ?? $code->default_uom, 'qty' => $qty, 'rate_snapshot_cents' => $priced['rate_cents'], 'amount_cents' => $amount,
            'calculation_snapshot_json' => $priced['calculation_snapshot'] + ['manual' => true, 'amount_given' => $amountCents !== null],
            'tax_treatment' => $code->tax_treatment, 'status' => ($amountCents === null && ($priced['missing_rate'] || $priced['is_poa'])) ? 'needs_review' : 'pending',
            'source_type' => null, 'source_id' => null, 'source_activity_id' => null, 'activity_version' => 1,
            'is_manual' => true, 'manual_reason' => $reason, 'created_by' => $createdBy ?? auth()->id(),
        ]);
    }

    /** POA / review queue: Finance sets the amount and the charge becomes billable. */
    public function review(Charge $charge, int $amountCents, string $note): Charge
    {
        if ($charge->status !== 'needs_review') {
            throw new \InvalidArgumentException('Only charges awaiting review can be priced by hand.');
        }
        $charge->update(['amount_cents' => $amountCents, 'status' => 'approved', 'calculation_snapshot_json' => ($charge->calculation_snapshot_json ?? []) + ['reviewed' => ['amount_cents' => $amountCents, 'note' => $note, 'by' => auth()->id(), 'at' => now()->toIso8601String()]]]);

        return $charge->fresh();
    }

    /** Reversal = a negative twin; the original is marked reversed. Invoiced charges are reversed through credit notes instead. */
    public function reverse(Charge $charge, string $reason): ?Charge
    {
        if ($charge->status === 'reversed' || $charge->reversal_of_charge_id !== null) {
            return null;
        }
        // An uninvoiced original simply leaves the pool (its twin is an audit row); an invoiced one stays and its negative twin is billable.
        $wasInvoiced = $charge->status === 'invoiced';

        return DB::transaction(function () use ($charge, $reason, $wasInvoiced): Charge {
            if (! $wasInvoiced) {
                $charge->update(['status' => 'reversed']);
            }

            return Charge::query()->create([
                'job_id' => $charge->job_id, 'client_id' => $charge->client_id, 'charge_date' => today(), 'charge_code_id' => $charge->charge_code_id,
                'rate_card_id' => $charge->rate_card_id, 'rate_card_version' => $charge->rate_card_version, 'rate_item_id' => $charge->rate_item_id,
                'uom' => $charge->uom, 'qty' => -$charge->qty, 'rate_snapshot_cents' => $charge->rate_snapshot_cents, 'amount_cents' => -$charge->amount_cents,
                'calculation_snapshot_json' => ['reversal_reason' => $reason, 'of' => $charge->id], 'tax_treatment' => $charge->tax_treatment,
                'status' => $wasInvoiced ? 'approved' : 'reversed', 'source_type' => $charge->source_type, 'source_id' => $charge->source_id,
                'source_activity_id' => null, 'activity_version' => $charge->activity_version, 'reversal_of_charge_id' => $charge->id,
            ]);
        });
    }

    /** Flatten the payload for rule conditions: dotted keys for nested objects (container.size), plain keys otherwise. */
    private function context(array $payload): array
    {
        $context = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) && array_is_list($value) === false) {
                foreach ($value as $k => $v) {
                    if (! is_array($v)) {
                        $context["{$key}.{$k}"] = $v;
                    }
                }
            } elseif (! is_array($value)) {
                $context[$key] = $value;
            }
        }
        foreach (['container_size' => 'container.size', 'unpack_mode' => 'container.unpack_mode', 'line_count' => 'container.line_count', 'gross_weight_kg' => 'container.gross_weight_kg'] as $alias => $path) {
            if (isset($context[$path])) {
                $context[$alias] = $context[$path];
            }
        }

        return $context;
    }

    /** Condition = map of field → expected value or list of accepted values; string comparison, booleans strict. */
    private function matches(array $condition, array $context): bool
    {
        foreach ($condition as $field => $expected) {
            $actual = $context[$field] ?? null;
            if (is_array($expected)) {
                if (! in_array($actual, $expected, false)) {
                    return false;
                }
            } elseif ($expected === null) {
                if ($actual !== null && $actual !== '') {
                    return false;
                }
            } elseif (is_bool($expected)) {
                if ((bool) $actual !== $expected) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array{0: float, 1: array<string, mixed>}> quantities with extra pricing context */
    private function quantities(ChargeRule $rule, array $payload, int $clientId, string $eventName): array
    {
        return match ($rule->quantity_source) {
            'billable_qty' => [[(float) ($payload['billable_qty'] ?? 0), []]],
            'pallets' => [[(float) ($payload['pallet_count'] ?? collect($payload['lines'] ?? [])->where('unit_type', 'pallet')->sum('qty')), []]],
            'pallets_warehouse_plain' => [[(float) collect($payload['pallets'] ?? [])->where('pallet_source', 'warehouse_plain')->count(), []]],
            'labels' => [[(float) ($payload['label_count'] ?? 0), []]],
            'scans' => [[(float) ($payload['scan_count'] ?? 0), []]],
            'hours_business' => [[(float) ($payload['hours_business'] ?? 0), []]],
            'hours_after_hours' => [[(float) ($payload['hours_after_hours'] ?? 0), []]],
            'cbm' => [[(float) ($payload['cbm'] ?? 0), []]],
            'orders', 'one' => [[1.0, $this->pricingContext($payload)]],
            'cartons' => $this->cartonBands($rule, $payload, $clientId),
            default => [],
        };
    }

    /** outbound.packed lines with unit_type carton, grouped by the weight band the rule's code matches; one charge per code. */
    private function cartonBands(ChargeRule $rule, array $payload, int $clientId): array
    {
        $qty = 0.0;
        $weights = [];
        foreach ($payload['lines'] ?? [] as $line) {
            if (($line['unit_type'] ?? 'carton') !== 'carton') {
                continue;
            }
            $weight = (float) ($line['unit_weight_kg'] ?? 0);
            $probe = $this->rates->price($clientId, $rule->chargeCode->code, 1, ['weight_kg' => $weight]);
            if ($probe['missing_rate']) {
                continue;
            }
            $qty += (float) ($line['qty'] ?? $line['carton_qty'] ?? 0);
            $weights[] = $weight;
        }

        return $qty > 0 ? [[$qty, ['weight_kg' => max($weights)]]] : [];
    }

    private function pricingContext(array $payload): array
    {
        return array_filter([
            'cost_cents' => $payload['cost_cents'] ?? $payload['carrier_cost_cents'] ?? null,
            'base_cents' => $payload['customer_price_cents'] ?? $payload['client_price_cents'] ?? null,
            'zone' => $payload['zone'] ?? null,
            'carrier_id' => $payload['carrier_id'] ?? null,
            'service_level' => $payload['service_level'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function render(string $template, array $payload): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($payload[$m[1]] ?? ''), $template);
    }

    /** @return array{type: string, id: ?int} */
    private function source(string $eventName, array $payload): array
    {
        return match (true) {
            str_starts_with($eventName, 'task.') => ['type' => 'task', 'id' => $payload['task_id'] ?? null],
            str_starts_with($eventName, 'asn.') => ['type' => 'asn', 'id' => $payload['asn_id'] ?? null],
            str_starts_with($eventName, 'outbound.') => ['type' => 'order', 'id' => $payload['order_id'] ?? null],
            str_starts_with($eventName, 'shipment.'), str_starts_with($eventName, 'delivery.') => ['type' => 'shipment', 'id' => $payload['shipment_id'] ?? null],
            default => ['type' => 'snapshot', 'id' => $payload['stock_unit_id'] ?? null],
        };
    }

    private function codeHasBandedItems(int $clientId, ChargeCode $code): bool
    {
        $client = Client::query()->withoutGlobalScopes()->find($clientId);
        $cardIds = RateCard::query()->where('status', 'active')->where(fn ($q) => $q->where('client_id', $clientId)->orWhere('id', $client?->standard_rate_card_id))->pluck('id');

        return RateItem::query()->whereIn('rate_card_id', $cardIds)->where('charge_code_id', $code->id)
            ->where(fn ($q) => $q->whereNotNull('weight_band_min')->orWhereNotNull('weight_band_max')->orWhereNotNull('zone')->orWhereNotNull('carrier_id')->orWhereNotNull('service_level'))
            ->exists();
    }
}
