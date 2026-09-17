<?php

namespace App\Modules\Portal\Services;

use App\Modules\Warehouse\Models\Warehouse;
use App\Support\Contracts\RateService;

/**
 * CHANGE_REQUESTS #129: what the client sees next to the 存储等级 select on the order forms — its OWN bottom-level surcharge percent per
 * warehouse, read through RateService::price (a customer price, never cost / margin). Same rule as the inbound-list preview (#126):
 * customer price only, percent mode only; a missing rate, a POA row or a minimum charge means "not priced" and the warehouse is skipped.
 * Empty when no card of the client prices WH-STORAGE-TIER-PLT-WK as a percent, so the forms show no hint at all.
 */
final class PortalStorageTier
{
    public function __construct(private readonly RateService $rates) {}

    /** @return array<string, string> warehouse code → "10%" */
    public function surchargeByWarehouse(int $clientId): array
    {
        $out = [];
        foreach (Warehouse::query()->where('active', true)->orderBy('code')->pluck('code', 'id') as $warehouseId => $code) {
            $priced = $this->rates->price($clientId, 'WH-STORAGE-TIER-PLT-WK', 1, ['storage_tier' => 'bottom', 'warehouse_id' => (int) $warehouseId, 'base_cents' => 1_000_000]);
            if (! $priced['missing_rate'] && ! $priced['is_poa'] && ! $priced['min_charge_applied'] && ($priced['calculation_snapshot']['pricing_mode'] ?? null) === 'percent') {
                $out[(string) $code] = rtrim(rtrim(number_format($priced['amount_cents'] / 10_000, 2, '.', ''), '0'), '.').'%';
            }
        }

        return $out;
    }

    /**
     * One display string for the hint: "10%" when every warehouse prices the same, else "MEL 10% · SYD 20%"; null when nothing is priced.
     *
     * @param  array<string, string>  $byWarehouse
     */
    public static function summary(array $byWarehouse): ?string
    {
        if ($byWarehouse === []) {
            return null;
        }
        if (count(array_unique($byWarehouse)) === 1) {
            return (string) reset($byWarehouse);
        }

        return collect($byWarehouse)->map(fn (string $percent, string $code) => $code.' '.$percent)->implode(' · ');
    }
}
