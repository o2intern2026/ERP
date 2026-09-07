<?php

namespace App\Support\Fakes;

use App\Support\Contracts\RateService;
use DateTimeInterface;

/**
 * The Edward standard rate card (contracts/charge-codes.md) as an in-memory rate card, version 1, for every client.
 * Thresholds are the §4.8 / charge-codes.md §7 defaults (25 kg tailgate, 1200×1200×1400/1800/2400 mm, 800 kg,
 * 20 lines, 22.5 t). Codes without an Edward row return missing_rate=true — never $0 — unless withRate() sets one.
 */
final class FakeRateService implements RateService
{
    public const RATE_CARD_ID = 1;

    public const RATE_CARD_VERSION = 1;

    /** @var array<string, array{uom:string, rate:?int, poa?:bool, thresholds?:array<string, mixed>, band?:array{0:float, 1:?float}}> */
    public const EDWARD = [
        'TR-CARTAGE-20' => ['uom' => 'container_20', 'rate' => 123060, 'thresholds' => ['max_gross_weight_kg' => 22500]],
        'TR-CARTAGE-40' => ['uom' => 'container_40', 'rate' => 129160, 'thresholds' => ['max_gross_weight_kg' => 22500]],
        'WH-DEVAN-20-PLT' => ['uom' => 'container_20', 'rate' => 18000],
        'WH-DEVAN-20-LOOSE' => ['uom' => 'container_20', 'rate' => 40000, 'thresholds' => ['max_line_count' => 20]],
        'WH-DEVAN-20-MIXED' => ['uom' => 'container_20', 'rate' => null, 'poa' => true],
        'WH-DEVAN-40-PLT' => ['uom' => 'container_40', 'rate' => 28000],
        'WH-DEVAN-40-LOOSE' => ['uom' => 'container_40', 'rate' => 55000, 'thresholds' => ['max_line_count' => 20]],
        'WH-DEVAN-40-MIXED' => ['uom' => 'container_40', 'rate' => null, 'poa' => true],
        'WH-UNLOAD-PLT' => ['uom' => 'pallet', 'rate' => 400],
        'WH-PUTAWAY-PLT' => ['uom' => 'pallet', 'rate' => 450],
        'WH-WRAP-IN-PLT' => ['uom' => 'pallet', 'rate' => 450],
        'WH-LABEL-IN' => ['uom' => 'label', 'rate' => 30],
        'WH-STORAGE-PLT-WK' => ['uom' => 'pallet_week', 'rate' => 450, 'thresholds' => ['max_length_mm' => 1200, 'max_width_mm' => 1200, 'max_height_mm' => 1400, 'max_weight_kg' => 800]],
        'WH-STORAGE-PLT-WIDE-WK' => ['uom' => 'pallet_week', 'rate' => 900, 'thresholds' => ['max_long_side_mm' => 2400, 'max_short_side_mm' => 1200, 'max_height_mm' => 1400, 'max_weight_kg' => 800]],
        'WH-STORAGE-PLT-HIGH-WK' => ['uom' => 'pallet_week', 'rate' => 800, 'thresholds' => ['max_length_mm' => 1200, 'max_width_mm' => 1200, 'max_height_mm' => 1800, 'max_weight_kg' => 800]],
        'WH-STORAGE-PICKFACE-WK' => ['uom' => 'pickface_week', 'rate' => 650, 'thresholds' => ['slot_length_mm' => 2650, 'slot_width_mm' => 1000, 'slot_height_mm' => 600]],
        'WH-STORAGE-PLT-OVERWEIGHT-WK' => ['uom' => 'pallet_week', 'rate' => null, 'poa' => true, 'thresholds' => ['min_weight_kg' => 800]],
        'WH-PALLET-RENT-PLAIN-WK' => ['uom' => 'pallet_week', 'rate' => 70],
        'WH-PALLET-RENT-POOL-WK' => ['uom' => 'pallet_week', 'rate' => 200],
        'WH-ORDER-DESPATCH' => ['uom' => 'order', 'rate' => 500],
        'WH-ORDER-DESPATCH-URGENT' => ['uom' => 'order', 'rate' => 1500, 'thresholds' => ['cutoff_source' => 'clients.dispatch_cutoff_time']],
        'WH-PICK-PLT' => ['uom' => 'pallet', 'rate' => 400],
        'WH-PICK-CTN-GE45' => ['uom' => 'carton', 'rate' => 450, 'band' => [45.0, null]],
        'WH-PICK-CTN-22-45' => ['uom' => 'carton', 'rate' => 350, 'band' => [22.0, 44.99]],
        'WH-PICK-CTN-LT22' => ['uom' => 'carton', 'rate' => 150, 'band' => [0.0, 21.99]],
        'WH-LOAD-PLT' => ['uom' => 'pallet', 'rate' => 400],
        'WH-WRAP-OUT-PLT' => ['uom' => 'pallet', 'rate' => 450],
        'WH-LABEL-OUT' => ['uom' => 'label', 'rate' => 30],
        'VAS-SCAN' => ['uom' => 'scan', 'rate' => 50],
        'VAS-WASTE-CBM' => ['uom' => 'cbm', 'rate' => 8000, 'thresholds' => ['min_billable_qty' => 1]],
        'VAS-PALLET-PURCHASE' => ['uom' => 'pallet', 'rate' => 2500],
        'VAS-PALLET-PURCHASE-NONSTD' => ['uom' => 'pallet', 'rate' => 1500],
        'VAS-LABOUR-HR' => ['uom' => 'man_hour', 'rate' => 4000],
        'VAS-LABOUR-HR-AH' => ['uom' => 'man_hour', 'rate' => 5500],
        // Plan-required codes with no Edward rate: thresholds only, price() reports missing_rate.
        'TR-TAILGATE' => ['uom' => 'delivery', 'rate' => null, 'thresholds' => ['tailgate_weight_kg' => 25]],
    ];

    /** @var array<string, array{uom:string, rate:int}> */
    private array $overrides = [];

    /** Give a code a fixed rate for a test, e.g. withRate('TR-DELIVERY-BASE', 7500). */
    public function withRate(string $chargeCode, int $rateCents, string $uom = 'delivery'): self
    {
        $this->overrides[$chargeCode] = ['uom' => $uom, 'rate' => $rateCents];

        return $this;
    }

    public function price(int $clientId, string $chargeCode, float $qty, array $context = [], ?DateTimeInterface $at = null): array
    {
        $result = [
            'charge_code' => $chargeCode,
            'rate_item_id' => null,
            'rate_card_id' => null,
            'rate_card_version' => null,
            'uom' => null,
            'qty' => $qty,
            'rate_cents' => null,
            'amount_cents' => null,
            'min_charge_applied' => false,
            'is_poa' => false,
            'missing_rate' => false,
            'calculation_snapshot' => ['client_id' => $clientId, 'context' => $context, 'rate_card' => 'edward-standard'],
        ];

        $item = $this->overrides[$chargeCode] ?? self::EDWARD[$chargeCode] ?? null;

        if ($item === null || ($item['rate'] === null && ! ($item['poa'] ?? false))) {
            $result['missing_rate'] = true;

            return $result;
        }

        $codes = array_keys(self::EDWARD);
        $result['rate_item_id'] = (int) (array_search($chargeCode, $codes, true) ?: count($codes)) + 1;
        $result['rate_card_id'] = self::RATE_CARD_ID;
        $result['rate_card_version'] = self::RATE_CARD_VERSION;
        $result['uom'] = $item['uom'];

        $thresholds = $item['thresholds'] ?? [];

        if (($item['poa'] ?? false)
            || (isset($thresholds['max_gross_weight_kg']) && ($context['gross_weight_kg'] ?? 0) > $thresholds['max_gross_weight_kg'])
            || (isset($thresholds['max_line_count']) && ($context['line_count'] ?? 0) > $thresholds['max_line_count'])) {
            $result['is_poa'] = true;

            return $result;
        }

        if (isset($thresholds['min_billable_qty']) && $qty < $thresholds['min_billable_qty']) {
            $result['qty'] = (float) $thresholds['min_billable_qty'];
            $result['min_charge_applied'] = true;
        }

        $result['rate_cents'] = $item['rate'];
        $result['amount_cents'] = (int) round($item['rate'] * $result['qty'], 0, PHP_ROUND_HALF_UP);
        $result['calculation_snapshot']['thresholds'] = $thresholds;

        return $result;
    }

    public function thresholds(int $clientId, string $chargeCode): ?array
    {
        return self::EDWARD[$chargeCode]['thresholds'] ?? null;
    }

    public function suggestPalletClass(int $clientId, int $lengthMm, int $widthMm, int $heightMm, float $weightKg): ?string
    {
        $over = $this->thresholds($clientId, 'WH-STORAGE-PLT-OVERWEIGHT-WK');
        if ($over !== null && $weightKg >= $over['min_weight_kg']) {
            return 'overweight';
        }

        $std = $this->thresholds($clientId, 'WH-STORAGE-PLT-WK');
        if ($std !== null && $lengthMm <= $std['max_length_mm'] && $widthMm <= $std['max_width_mm'] && $heightMm <= $std['max_height_mm']) {
            return 'standard';
        }

        $high = $this->thresholds($clientId, 'WH-STORAGE-PLT-HIGH-WK');
        if ($high !== null && $lengthMm <= $high['max_length_mm'] && $widthMm <= $high['max_width_mm'] && $heightMm <= $high['max_height_mm']) {
            return 'oversize_high';
        }

        $wide = $this->thresholds($clientId, 'WH-STORAGE-PLT-WIDE-WK');
        if ($wide !== null && max($lengthMm, $widthMm) <= $wide['max_long_side_mm'] && min($lengthMm, $widthMm) <= $wide['max_short_side_mm'] && $heightMm <= $wide['max_height_mm']) {
            return 'oversize_wide';
        }

        return null;
    }
}
