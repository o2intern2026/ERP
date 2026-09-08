<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Client;
use App\Support\Contracts\RateService as RateServiceContract;
use DateTimeInterface;

/**
 * A5 pricing (contracts/services.md §4). Lookup order: the client's own active card at $at → the standard card the
 * client is bound to → missing rate (never $0). POA items price nothing and flag the charge for review.
 * Every number comes from the rate item (rate, min charge, bands, thresholds, markup) — nothing is hard-coded here.
 */
final class RateService implements RateServiceContract
{
    public function price(int $clientId, string $chargeCode, float $qty, array $context = [], ?DateTimeInterface $at = null): array
    {
        $at ??= now();
        $code = ChargeCode::query()->where('code', $chargeCode)->first();
        $base = ['charge_code' => $chargeCode, 'rate_item_id' => null, 'rate_card_id' => null, 'rate_card_version' => null, 'uom' => $code?->default_uom, 'qty' => $qty, 'rate_cents' => null, 'amount_cents' => 0, 'min_charge_applied' => false, 'is_poa' => false, 'missing_rate' => true, 'calculation_snapshot' => ['context' => $context]];

        if ($code === null) {
            return $base + ['reason' => 'unknown_code'];
        }

        [$item, $card, $source] = $this->findItem($clientId, $code, $context, $at);
        if ($item === null) {
            return $base + ['reason' => 'no_rate_item'];
        }

        $snapshot = ['card' => $source, 'pricing_mode' => $item->pricing_mode, 'thresholds' => $item->threshold_json, 'weight_band' => [$item->weight_band_min, $item->weight_band_max], 'zone' => $item->zone, 'context' => $context];
        $result = array_replace($base, ['rate_item_id' => $item->id, 'rate_card_id' => $card->id, 'rate_card_version' => $card->version, 'missing_rate' => false]);

        if ($item->is_poa) {
            return array_replace($result, ['is_poa' => true, 'amount_cents' => 0, 'calculation_snapshot' => $snapshot + ['poa' => true]]);
        }

        $minQty = (float) $item->threshold('min_billable_qty', 0);
        $billedQty = max($qty, $minQty);

        $amount = match ($item->pricing_mode) {
            'cost_plus' => $this->costPlus($item, $clientId, (int) ($context['cost_cents'] ?? 0), $snapshot),
            'percent' => (int) round(((int) ($context['base_cents'] ?? 0)) * ((float) ($item->markup_percent ?? 0)) / 100),
            default => (int) round(($item->rate_cents ?? 0) * $billedQty),
        };

        $minApplied = false;
        if ($item->min_charge_cents !== null && $amount < $item->min_charge_cents) {
            $amount = $item->min_charge_cents;
            $minApplied = true;
        }

        return array_replace($result, [
            'qty' => $billedQty,
            'rate_cents' => $item->rate_cents,
            'amount_cents' => $amount,
            'min_charge_applied' => $minApplied,
            'calculation_snapshot' => $snapshot + ['billed_qty' => $billedQty, 'min_billable_qty' => $minQty, 'min_charge_cents' => $item->min_charge_cents],
        ]);
    }

    public function thresholds(int $clientId, string $chargeCode): ?array
    {
        $code = ChargeCode::query()->where('code', $chargeCode)->first();
        if ($code === null) {
            return null;
        }
        [$item] = $this->findItem($clientId, $code, [], now());

        return $item?->threshold_json;
    }

    /** §4.8: standard → oversize_high → oversize_wide; weight ≥ min_weight_kg → overweight; null = beyond every band (POA). */
    public function suggestPalletClass(int $clientId, int $lengthMm, int $widthMm, int $heightMm, float $weightKg): ?string
    {
        $over = $this->thresholds($clientId, 'WH-STORAGE-PLT-OVERWEIGHT-WK');
        if ($over !== null && isset($over['min_weight_kg']) && $weightKg >= (float) $over['min_weight_kg']) {
            return 'overweight';
        }

        $long = max($lengthMm, $widthMm);
        $short = min($lengthMm, $widthMm);

        foreach ([['WH-STORAGE-PLT-WK', 'standard'], ['WH-STORAGE-PLT-HIGH-WK', 'oversize_high'], ['WH-STORAGE-PLT-WIDE-WK', 'oversize_wide']] as [$code, $class]) {
            $t = $this->thresholds($clientId, $code);
            if ($t === null) {
                continue;
            }
            if (isset($t['max_weight_kg']) && $weightKg >= (float) $t['max_weight_kg']) {
                continue;
            }
            $fitsHeight = ! isset($t['max_height_mm']) || $heightMm <= (int) $t['max_height_mm'];
            $fitsFootprint = isset($t['max_long_side_mm'])
                ? ($long <= (int) $t['max_long_side_mm'] && $short <= (int) $t['max_short_side_mm'])
                : ($long <= (int) ($t['max_length_mm'] ?? PHP_INT_MAX) && $short <= (int) ($t['max_width_mm'] ?? PHP_INT_MAX));
            if ($fitsHeight && $fitsFootprint) {
                return $class;
            }
        }

        return null;
    }

    /** @return array{0: ?RateItem, 1: ?RateCard, 2: ?string} item, card, 'client' | 'standard' */
    private function findItem(int $clientId, ChargeCode $code, array $context, DateTimeInterface $at): array
    {
        $client = Client::query()->withoutGlobalScopes()->find($clientId);

        $clientCard = RateCard::query()->where('client_id', $clientId)->where('status', 'active')
            ->whereDate('effective_from', '<=', $at)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $at))
            ->orderByDesc('version')->first();
        if ($clientCard && ($item = $this->matchItem($clientCard, $code, $context))) {
            return [$item, $clientCard, 'client'];
        }

        $standard = $client?->standard_rate_card_id ? RateCard::query()->whereKey($client->standard_rate_card_id)->where('status', 'active')->first() : null;
        if ($standard && $standard->isEffectiveOn($at) && ($item = $this->matchItem($standard, $code, $context))) {
            return [$item, $standard, 'standard'];
        }

        return [null, null, null];
    }

    private function matchItem(RateCard $card, ChargeCode $code, array $context): ?RateItem
    {
        $items = RateItem::query()->where('rate_card_id', $card->id)->where('charge_code_id', $code->id)->get();
        if ($items->isEmpty()) {
            return null;
        }

        // Most specific first: carrier + service level, then zone, then weight band / pallet class, then the plain row.
        $items = $items->sortByDesc(fn (RateItem $i) => ($i->carrier_id ? 8 : 0) + ($i->service_level ? 4 : 0) + ($i->zone ? 2 : 0) + ($i->weight_band_min !== null || $i->pallet_class ? 1 : 0));

        foreach ($items as $item) {
            if ($item->carrier_id && (int) ($context['carrier_id'] ?? 0) !== (int) $item->carrier_id) {
                continue;
            }
            if ($item->service_level && ($context['service_level'] ?? null) !== $item->service_level) {
                continue;
            }
            if ($item->zone && ($context['zone'] ?? null) !== $item->zone) {
                continue;
            }
            if ($item->pallet_class && isset($context['pallet_class']) && $context['pallet_class'] !== $item->pallet_class) {
                continue;
            }
            if ($item->weight_band_min !== null || $item->weight_band_max !== null) {
                $w = $context['weight_kg'] ?? null;
                if ($w === null) {
                    continue;
                }
                if ($item->weight_band_min !== null && (float) $w < (float) $item->weight_band_min) {
                    continue;
                }
                if ($item->weight_band_max !== null && (float) $w > (float) $item->weight_band_max) {
                    continue;
                }
            }

            return $item;
        }

        return null;
    }

    private function costPlus(RateItem $item, int $clientId, int $costCents, array &$snapshot): int
    {
        $markup = $item->markup_percent !== null ? (float) $item->markup_percent : (float) (Client::query()->withoutGlobalScopes()->find($clientId)?->default_markup_percent ?? 0);
        $snapshot['cost_cents'] = $costCents;
        $snapshot['markup_percent'] = $markup;

        return (int) round($costCents * (1 + $markup / 100));
    }
}
