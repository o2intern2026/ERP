<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use App\Support\Exceptions\RuleViolation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * A5 versioning: a price change is a new draft version copied from the current one; activating it (after a second
 * person approves, PLT-7) supersedes the previous version from the new effective date. Old charges keep their snapshots.
 */
final class RateCardService
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function newVersion(RateCard $from, User $by, CarbonInterface $effectiveFrom, ?string $notes = null): RateCard
    {
        return DB::transaction(function () use ($from, $by, $effectiveFrom, $notes): RateCard {
            $latest = RateCard::query()->where('client_id', $from->client_id)->where('is_standard', $from->is_standard)->max('version');
            $card = RateCard::query()->create([
                'client_id' => $from->client_id, 'name' => $from->name, 'currency' => $from->currency, 'version' => $latest + 1,
                'effective_from' => $effectiveFrom->toDateString(), 'status' => 'draft', 'is_standard' => $from->is_standard, 'created_by' => $by->id, 'notes' => $notes,
            ]);
            foreach ($from->items as $item) {
                // replicate() copies the stored attributes as they are; pushing getAttributes() through create() re-ran the JSON cast and
                // double-encoded threshold_json on every copied version (audit 2026-09-22 FIN-08, CR #140 — the ThresholdJson cast heals old rows).
                $copy = $item->replicate();
                $copy->rate_card_id = $card->id;
                $copy->save();
            }

            return $card->fresh();
        });
    }

    public function createClientCard(Client $client, User $by, CarbonInterface $effectiveFrom, string $name): RateCard
    {
        return RateCard::query()->create(['client_id' => $client->id, 'name' => $name, 'version' => 1, 'effective_from' => $effectiveFrom->toDateString(), 'status' => 'draft', 'is_standard' => false, 'created_by' => $by->id]);
    }

    /** @param array<string, mixed> $data rate_cents, min_charge_cents, is_poa, markup_percent, pricing_mode, threshold_json, weight bands, zone, notes */
    public function updateItem(RateItem $item, array $data): RateItem
    {
        if ($item->rateCard->status !== 'draft') {
            throw new RuleViolation('Rate items of an active card are immutable — create a new version.', 'billing.rate_cards.errors.active_immutable');
        }
        $this->refuseWhenLocked($item->rateCard);
        $item->update($data);

        return $item->fresh();
    }

    public function addItem(RateCard $card, array $data): RateItem
    {
        if ($card->status !== 'draft') {
            throw new RuleViolation('Only draft cards accept new items.', 'billing.rate_cards.errors.draft_only_items');
        }
        $this->refuseWhenLocked($card);

        return RateItem::query()->create($data + ['rate_card_id' => $card->id, 'pricing_mode' => $data['pricing_mode'] ?? 'fixed']);
    }

    /**
     * Audit 2026-09-22 FIN-08 (CR #140): what the second person approved must be what goes live. A draft's items are frozen from the
     * moment it is submitted (a `rate_card_change` approval pending or approved); the requester withdraws the request in the approval
     * centre or copies a new version to change anything. A rejected / cancelled request unlocks the draft again.
     */
    public function lockedForApproval(RateCard $card): bool
    {
        return Approval::query()->withoutGlobalScopes()->where('type', 'rate_card_change')->where('subject_type', 'rate_card')->where('subject_id', $card->id)->whereIn('status', ['pending', 'approved'])->exists();
    }

    private function refuseWhenLocked(RateCard $card): void
    {
        if ($this->lockedForApproval($card)) {
            throw new RuleViolation("Rate card v{$card->version} is locked: submitted for approval.", 'billing.rate_cards.errors.locked_for_approval', ['version' => $card->version]);
        }
    }

    /**
     * The version this card replaces when it goes live: the active card of the same client / standard flag, else the highest lower version
     * (nothing active yet). Null for a first version.
     */
    public function previousVersion(RateCard $card): ?RateCard
    {
        $siblings = RateCard::query()->with('items')->where('id', '!=', $card->id)->where('is_standard', $card->is_standard)->where('client_id', $card->client_id);

        return $siblings->clone()->where('status', 'active')->orderByDesc('version')->first()
            ?? $siblings->clone()->where('version', '<', $card->version)->orderByDesc('version')->first();
    }

    /** Item fields whose change is a price change the approver must see (rate, minimum, POA, mode, percent, thresholds). */
    private const COMPARED = ['rate_cents', 'min_charge_cents', 'is_poa', 'pricing_mode', 'markup_percent', 'threshold_json'];

    /**
     * Audit 2026-09-22 FIN-08 (CR #140): what a draft changes against the previous version, so the approver does not compare 35 rows in
     * two tabs by eye. Items match on what they price (code + pallet class + weight band + zone + service level + warehouse + carrier);
     * a matched item is `same` or `changed` (field → [old, new]), an unmatched one `added`; previous items with no match are `removed`.
     *
     * @return array{previous: RateCard, items: array<int, array{state: string, changes: array<string, array{0: mixed, 1: mixed}>}>, removed: EloquentCollection<int, RateItem>, counts: array{added: int, changed: int, removed: int}}|null
     */
    public function diffAgainstPrevious(RateCard $card): ?array
    {
        $previous = $this->previousVersion($card);
        if ($previous === null) {
            return null;
        }
        $key = fn (RateItem $i): string => implode('|', [$i->charge_code_id, $i->pallet_class ?? '', $i->weight_band_min ?? '', $i->weight_band_max ?? '', $i->zone ?? '', $i->service_level ?? '', $i->warehouse_id ?? '', $i->carrier_id ?? '']);
        $old = $previous->items->groupBy($key)->map(fn ($group) => $group->values()->all())->all();
        $items = [];
        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0];
        foreach ($card->items as $item) {
            $k = $key($item);
            $match = isset($old[$k]) && $old[$k] !== [] ? array_shift($old[$k]) : null;
            if ($match === null) {
                $items[$item->id] = ['state' => 'added', 'changes' => []];
                $counts['added']++;

                continue;
            }
            $changes = [];
            foreach (self::COMPARED as $field) {
                if ($this->normalise($match->{$field}) !== $this->normalise($item->{$field})) {
                    $changes[$field] = [$match->{$field}, $item->{$field}];
                }
            }
            $items[$item->id] = ['state' => $changes === [] ? 'same' : 'changed', 'changes' => $changes];
            if ($changes !== []) {
                $counts['changed']++;
            }
        }
        $removed = (new EloquentCollection(collect($old)->flatten(1)->values()->all()))->load('chargeCode');
        $counts['removed'] = $removed->count();

        return ['previous' => $previous, 'items' => $items, 'removed' => $removed, 'counts' => $counts];
    }

    /** Comparable scalar: decimals as numbers ("15.00" = "15"), JSON key order ignored, null stays null. */
    private function normalise(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return json_encode($value);
        }
        if ($value === null || is_bool($value)) {
            return $value;
        }

        return is_numeric($value) ? (string) (float) $value : (string) $value;
    }

    public function requestActivation(RateCard $card, User $by, ?string $note = null): void
    {
        if ($card->status !== 'draft') {
            throw new RuleViolation('Only drafts can be submitted for approval.', 'billing.rate_cards.errors.draft_only_submit');
        }
        $this->approvals->request('rate_card_change', 'rate_card', $card->id, $by, ['client_id' => $card->client_id, 'request_note' => $note ?? __('billing.rate_cards.approval_note', ['name' => $card->name, 'version' => $card->version, 'date' => $card->effective_from->toDateString()])]);
    }

    public function activate(RateCard $card, User $by): RateCard
    {
        if ($card->status !== 'draft') {
            throw new RuleViolation('Only drafts can be activated.', 'billing.rate_cards.errors.draft_only_activate');
        }
        if (! $this->approvals->isApproved('rate_card_change', 'rate_card', $card->id)) {
            throw new RuleViolation('A second person must approve this rate card version first (PLT-7).', 'billing.rate_cards.errors.not_approved');
        }

        return DB::transaction(function () use ($card, $by): RateCard {
            $previous = RateCard::query()->where('id', '!=', $card->id)->where('client_id', $card->client_id)->where('is_standard', $card->is_standard)->where('status', 'active')->get();
            foreach ($previous as $old) {
                $old->update(['status' => 'superseded', 'effective_to' => $card->effective_from->copy()->subDay()->toDateString()]);
                if ($card->is_standard) {
                    Client::query()->withoutGlobalScopes()->where('standard_rate_card_id', $old->id)->update(['standard_rate_card_id' => $card->id]);
                }
            }
            $card->update(['status' => 'active', 'approved_by' => $by->id]);

            return $card->fresh();
        });
    }
}
