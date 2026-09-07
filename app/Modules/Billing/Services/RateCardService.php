<?php

namespace App\Modules\Billing\Services;

use App\Models\User;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Services\ApprovalService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
                RateItem::query()->create(collect($item->getAttributes())->except(['id', 'rate_card_id', 'created_at', 'updated_at'])->all() + ['rate_card_id' => $card->id]);
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
            throw new InvalidArgumentException('Rate items of an active card are immutable — create a new version.');
        }
        $item->update($data);

        return $item->fresh();
    }

    public function addItem(RateCard $card, array $data): RateItem
    {
        if ($card->status !== 'draft') {
            throw new InvalidArgumentException('Only draft cards accept new items.');
        }

        return RateItem::query()->create($data + ['rate_card_id' => $card->id, 'pricing_mode' => $data['pricing_mode'] ?? 'fixed']);
    }

    public function requestActivation(RateCard $card, User $by, ?string $note = null): void
    {
        if ($card->status !== 'draft') {
            throw new InvalidArgumentException('Only drafts can be submitted for approval.');
        }
        $this->approvals->request('rate_card_change', 'rate_card', $card->id, $by, ['client_id' => $card->client_id, 'request_note' => $note ?? "{$card->name} v{$card->version} effective {$card->effective_from->toDateString()}"]);
    }

    public function activate(RateCard $card, User $by): RateCard
    {
        if ($card->status !== 'draft') {
            throw new InvalidArgumentException('Only drafts can be activated.');
        }
        if (! $this->approvals->isApproved('rate_card_change', 'rate_card', $card->id)) {
            throw new InvalidArgumentException('A second person must approve this rate card version first (PLT-7).');
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
