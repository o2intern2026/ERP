<?php

namespace App\Modules\Transport\Support;

use App\Support\Contracts\RateService;

/**
 * Pickup-side tailgate of an inbound collection (CHANGE_REQUESTS #124; extracted for #125 so the portal's collection estimate and
 * Transport's shipment use ONE rule): any declared piece (or, without packages, any goods line's per-carton weight) at or above
 * the client's TR-TAILGATE threshold (`tailgate_weight_kg`, default 25 kg). The receiver is our warehouse, so "residential"
 * never applies.
 */
final class CollectionTailgate
{
    /** Tailgate weight threshold when the client's card names none (Orders TailgateRule::DEFAULT_WEIGHT_KG). */
    public const DEFAULT_KG = 25.0;

    /**
     * @param  list<array<string, mixed>>  $packages  declared pieces — `weight_kg` per piece
     * @param  list<array<string, mixed>>  $lines  goods lines with weight + dims — `weight_kg` = the line total, `expected_cartons`
     */
    public static function required(int $clientId, array $packages, array $lines, ?RateService $rates = null): bool
    {
        $rates ??= app(RateService::class);
        $threshold = (float) (($rates->thresholds($clientId, 'TR-TAILGATE')['tailgate_weight_kg'] ?? null) ?: self::DEFAULT_KG);
        $pieces = $packages !== []
            ? array_map(fn ($p) => (float) ($p['weight_kg'] ?? 0), $packages)
            : array_map(fn ($l) => (float) ($l['weight_kg'] ?? 0) / max(1, (int) ($l['expected_cartons'] ?? 1)), $lines);
        $heaviest = $pieces === [] ? 0.0 : max($pieces);

        return $heaviest > 0 && $heaviest >= $threshold;
    }
}
