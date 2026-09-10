<?php

namespace App\Modules\Transport\Adapters;

use App\Support\Contracts\CarrierAdapter;
use InvalidArgumentException;

final class ManualCarrierAdapter implements CarrierAdapter
{
    public function source(): string
    {
        return 'manual';
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
        $quotes = [];

        foreach ($request['manual_quotes'] ?? [] as $quote) {
            $costCents = filter_var($quote['cost_cents'] ?? null, FILTER_VALIDATE_INT);
            if ($costCents === false || $costCents < 1) {
                continue;
            }

            $quotes[] = [
                'service_code' => (string) ($quote['service_code'] ?? 'manual'),
                'service_name' => (string) ($quote['service_name'] ?? 'Manual'),
                'service_level' => (string) ($quote['service_level'] ?? 'standard'),
                'cost_cents' => $costCents,
                'eta_days' => isset($quote['eta_days']) ? (int) $quote['eta_days'] : null,
                'pickup_dates' => array_values($quote['pickup_dates'] ?? []),
                'raw' => ['entered_manually' => true] + ($quote['raw'] ?? []) + [
                    'customer_price_cents' => isset($quote['customer_price_cents'])
                        ? (int) $quote['customer_price_cents']
                        : null,
                ],
            ];
        }

        return $quotes;
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        $bookingRef = trim((string) ($options['quote_ref'] ?? ''));
        if ($bookingRef === '') {
            throw new InvalidArgumentException(__('transport.booking.manual_reference_required'));
        }

        return [
            'booking_ref' => $bookingRef,
            'tracking_number' => isset($request['tracking_number'])
                ? trim((string) $request['tracking_number']) ?: null
                : null,
            'label_path' => null,
            'status' => 'booked_manually',
            'raw' => ['entered_manually' => true, 'service_code' => $serviceCode],
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
