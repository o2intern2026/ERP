<?php

namespace App\Modules\Transport\Adapters;

use App\Support\Contracts\CarrierAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

final class TransdirectAdapter implements CarrierAdapter
{
    public function __construct(private readonly HttpFactory $http) {}

    public function source(): string
    {
        return 'transdirect';
    }

    public function capabilities(): array
    {
        return [
            'quote' => true,
            'book' => true,
            'cancel' => true,
            'label' => true,
            'tracking' => 'poll',
            'pod' => 'manual',
        ];
    }

    public function quote(array $request): array
    {
        if (! $this->configured() || ! $this->completeParty($request['sender'] ?? []) || ! $this->completeParty($request['receiver'] ?? [])) {
            return [];
        }

        try {
            $response = $this->client()->post('bookings/v4', $this->payload($request));
        } catch (ConnectionException) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $body = $response->json();
        if (! is_array($body)) {
            return [];
        }

        $bookingId = isset($body['id']) ? (string) $body['id'] : null;
        $options = [];

        foreach ($body['quotes'] ?? [] as $code => $quote) {
            if (! is_array($quote) || ! is_numeric($quote['total'] ?? null)) {
                continue;
            }

            $costCents = (int) round((float) $quote['total'] * 100, 0, PHP_ROUND_HALF_UP);
            if ($costCents < 1) {
                continue;
            }

            $options[] = [
                'service_code' => (string) $code,
                'service_name' => (string) ($quote['service'] ?? $code),
                'service_level' => $this->serviceLevel((string) $code),
                'cost_cents' => $costCents,
                'eta_days' => $this->etaDays($quote['transit_time'] ?? null),
                'pickup_dates' => array_values(array_map('strval', $quote['pickup_dates'] ?? [])),
                'raw' => $quote + ['booking_id' => $bookingId],
            ];
        }

        return $options;
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        $bookingRef = trim((string) ($options['quote_ref'] ?? $request['quote_ref'] ?? ''));
        if (! $this->configured()
            || $bookingRef === ''
            || ! $this->completeParty($request['sender'] ?? [])
            || ! $this->completeParty($request['receiver'] ?? [])) {
            return $this->failedBooking($bookingRef);
        }

        try {
            $updated = $this->client()->put("bookings/v4/{$bookingRef}", $this->payload($request));
            if (! $updated->successful()) {
                return $this->failedBooking($bookingRef, ['update' => $updated->json()]);
            }

            $confirmed = $this->client()->post("bookings/v4/{$bookingRef}/confirm", [
                'courier' => $serviceCode,
                'pickup-date' => $options['pickup_date'] ?? $request['requested_date'] ?? null,
            ]);
            if (! $confirmed->successful()) {
                return $this->failedBooking($bookingRef, ['confirm' => $confirmed->json()]);
            }

            $fetched = $this->client()->get("bookings/v4/{$bookingRef}");
            $details = $fetched->successful() && is_array($fetched->json()) ? $fetched->json() : [];
        } catch (ConnectionException) {
            return $this->failedBooking($bookingRef);
        }

        return [
            'booking_ref' => $bookingRef,
            'tracking_number' => isset($details['connote']) ? (string) $details['connote'] : null,
            'label_path' => isset($details['label']) ? (string) $details['label'] : null,
            'status' => (string) ($details['status'] ?? 'confirmed'),
            'raw' => ['booking' => $details],
        ];
    }

    public function cancel(string $bookingRef): bool
    {
        if (! $this->configured() || trim($bookingRef) === '') {
            return false;
        }

        try {
            return $this->client()->delete("bookings/v4/{$bookingRef}")->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function label(string $bookingRef): ?string
    {
        if (! $this->configured() || trim($bookingRef) === '') {
            return null;
        }

        try {
            $response = $this->client()->get("bookings/v4/{$bookingRef}/label");
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    public function tracking(string $bookingRef): array
    {
        if (! $this->configured() || trim($bookingRef) === '') {
            return [];
        }

        try {
            $response = $this->client(false)->get("bookings/track/v4/{$bookingRef}");
        } catch (ConnectionException) {
            return [];
        }

        return $response->successful() ? $this->parseTrackingHtml($response->body()) : [];
    }

    private function configured(): bool
    {
        return trim((string) config('services.transdirect.api_key')) !== '';
    }

    private function client(bool $json = true): PendingRequest
    {
        $request = $this->http
            ->baseUrl(rtrim((string) config('services.transdirect.base_url'), '/').'/')
            ->timeout(15)
            ->withHeaders(['Api-key' => (string) config('services.transdirect.api_key')]);

        return $json ? $request->acceptJson() : $request->accept('text/html');
    }

    /** @return array<string, mixed> */
    private function payload(array $request): array
    {
        return [
            'declared_value' => round(((int) $request['declared_value_cents']) / 100, 2),
            'description' => (string) ($request['description'] ?? 'Shipment'),
            'referrer' => 'API',
            'items' => array_map(fn (array $item): array => [
                'weight' => (float) $item['weight_kg'],
                // B5e records that current plugins send centimetres; the first demo call must confirm this unit.
                'length' => round(((int) $item['length_mm']) / 10, 1),
                'width' => round(((int) $item['width_mm']) / 10, 1),
                'height' => round(((int) $item['height_mm']) / 10, 1),
                'quantity' => (int) $item['qty'],
                'description' => (string) $item['description'],
            ], $request['items']),
            'sender' => $this->party($request['sender']),
            'receiver' => $this->party($request['receiver']),
            'tailgate_pickup' => (bool) $request['tailgate_pickup'],
            'tailgate_delivery' => (bool) $request['tailgate_delivery'],
        ];
    }

    /** @return array<string, mixed> */
    private function party(array $party): array
    {
        return [
            'name' => $party['name'] ?? null,
            'company_name' => $party['company_name'] ?? null,
            'email' => $party['email'] ?? null,
            'phone' => $party['phone'] ?? null,
            'address' => $party['address'],
            'suburb' => $party['suburb'],
            'state' => $party['state'],
            'postcode' => $party['postcode'],
            'type' => $party['type'],
            'country' => 'AU',
        ];
    }

    /** @param array<string, mixed> $party */
    private function completeParty(array $party): bool
    {
        foreach (['address', 'suburb', 'state', 'postcode', 'type'] as $key) {
            if (trim((string) ($party[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function serviceLevel(string $code): string
    {
        $code = strtolower($code);

        return match (true) {
            str_contains($code, 'sameday') => 'same_day',
            (bool) preg_match('/(?:nine|ten|twelve|overnight|express|elite|priority)/', $code) => 'express',
            default => 'standard',
        };
    }

    private function etaDays(mixed $transitTime): ?int
    {
        preg_match_all('/\d+/', (string) $transitTime, $matches);
        if ($matches[0] === []) {
            return null;
        }

        return max(array_map('intval', $matches[0]));
    }

    /** @return list<array{status:string, description:?string, location:?string, occurred_at:?string, raw:array<string, mixed>}> */
    private function parseTrackingHtml(string $html): array
    {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $rows);
        $events = [];

        foreach ($rows[1] as $row) {
            preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/is', $row, $columns);
            $cells = array_map(
                fn (string $cell): string => trim(html_entity_decode(strip_tags($cell))),
                $columns[1]
            );

            if (count($cells) < 4 || strtolower($cells[0]) === 'status') {
                continue;
            }

            $occurredAt = null;
            try {
                $occurredAt = CarbonImmutable::parse("{$cells[1]} {$cells[2]}", 'Australia/Melbourne')
                    ->toIso8601String();
            } catch (Throwable) {
                // The upstream endpoint is an HTML table and may omit a parseable timestamp.
            }

            $events[] = [
                'status' => $cells[0],
                'description' => $cells[4] ?? null,
                'location' => $cells[3] ?: null,
                'occurred_at' => $occurredAt,
                'raw' => ['cells' => $cells],
            ];
        }

        return $events;
    }

    /** @return array{booking_ref:string, tracking_number:null, label_path:null, status:string, raw:array<string, mixed>} */
    private function failedBooking(string $bookingRef, array $raw = []): array
    {
        return [
            'booking_ref' => $bookingRef,
            'tracking_number' => null,
            'label_path' => null,
            'status' => 'request_failed',
            'raw' => $raw,
        ];
    }
}
