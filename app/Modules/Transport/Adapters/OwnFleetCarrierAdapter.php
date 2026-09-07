<?php

namespace App\Modules\Transport\Adapters;

use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\RateService;
use Illuminate\Support\Str;

final class OwnFleetCarrierAdapter implements CarrierAdapter
{
    public function __construct(private readonly RateService $rates) {}

    public function source(): string
    {
        return 'own_fleet';
    }

    public function capabilities(): array
    {
        return [
            'quote' => true,
            'book' => true,
            'cancel' => true,
            'label' => false,
            'tracking' => 'none',
            'pod' => 'manual',
        ];
    }

    public function quote(array $request): array
    {
        $clientId = (int) ($request['client_id'] ?? 0);
        if ($clientId < 1) {
            return [];
        }

        $price = $this->rates->price($clientId, 'TR-DELIVERY-BASE', 1, [
            'zone' => $request['zone'] ?? null,
            'weight_kg' => collect($request['items'])->sum(
                fn (array $item): float => (float) $item['weight_kg'] * (int) $item['qty']
            ),
            'service_level' => 'standard',
        ]);

        if ($price['missing_rate'] || $price['is_poa'] || $price['amount_cents'] === null) {
            return [];
        }

        return [[
            'service_code' => 'own_fleet.standard',
            'service_name' => 'Own fleet',
            'service_level' => 'standard',
            'cost_cents' => (int) $price['amount_cents'],
            'eta_days' => (int) ($request['own_fleet_eta_days'] ?? 1),
            'pickup_dates' => isset($request['requested_date']) ? [(string) $request['requested_date']] : [],
            'raw' => ['pricing_mode' => 'fixed', 'rate' => $price],
        ]];
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        return [
            'booking_ref' => (string) ($options['quote_ref'] ?? 'OWN-'.Str::upper(Str::random(12))),
            'tracking_number' => null,
            'label_path' => null,
            'status' => 'confirmed',
            'raw' => ['service_code' => $serviceCode],
        ];
    }

    public function cancel(string $bookingRef): bool
    {
        return trim($bookingRef) !== '';
    }

    public function label(string $bookingRef): ?string
    {
        return null;
    }

    public function tracking(string $bookingRef): array
    {
        return [];
    }
}
