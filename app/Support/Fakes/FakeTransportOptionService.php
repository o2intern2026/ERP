<?php

namespace App\Support\Fakes;

use App\Support\Contracts\TransportOptionService;

/**
 * Three deterministic options per shipment: own_fleet fixed $75 (ERP_PLAN §6.4 example), transdirect and eiz
 * at cost × 1.20 markup. Flags follow §5.6 B5d: cheapest = lowest price, fastest = lowest eta, recommended = cheapest.
 */
final class FakeTransportOptionService implements TransportOptionService
{
    public const MARKUP_PERCENT = 20.0;

    public function quote(int $shipmentId, string $stage): array
    {
        $now = now();
        $base = [
            ['carrier_id' => 1, 'source' => 'own_fleet', 'service_level' => 'standard', 'cost_cents' => 5000, 'customer_price_cents' => 7500, 'eta_days' => 1],
            ['carrier_id' => 2, 'source' => 'transdirect', 'service_level' => 'standard', 'cost_cents' => 6000, 'customer_price_cents' => (int) round(6000 * (1 + self::MARKUP_PERCENT / 100)), 'eta_days' => 3],
            ['carrier_id' => 3, 'source' => 'eiz', 'service_level' => 'standard', 'cost_cents' => 5500, 'customer_price_cents' => (int) round(5500 * (1 + self::MARKUP_PERCENT / 100)), 'eta_days' => 4],
        ];

        $cheapest = min(array_column($base, 'customer_price_cents'));
        $fastest = min(array_column($base, 'eta_days'));

        $quotes = [];
        foreach ($base as $i => $q) {
            $quotes[] = $q + [
                'transport_quote_id' => $shipmentId * 10 + $i + 1,
                'is_recommended' => $q['customer_price_cents'] === $cheapest,
                'is_cheapest' => $q['customer_price_cents'] === $cheapest,
                'is_fastest' => $q['eta_days'] === $fastest,
                'quoted_at' => $now->toIso8601String(),
                'expires_at' => $now->copy()->addDays(7)->toIso8601String(),
            ];
        }

        return $quotes;
    }
}
