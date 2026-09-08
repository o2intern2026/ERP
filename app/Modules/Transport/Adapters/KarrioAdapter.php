<?php

namespace App\Modules\Transport\Adapters;

use App\Support\Contracts\CarrierAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Karrio — the open-source, self-hosted multi-carrier shipping API (contracts/carriers.md, decision 2026-09-08).
 * One adapter covers every carrier connected inside Karrio (its built-in "custom carrier" with a rate sheet for
 * demos; Australia Post / Sendle / TNT / Allied … once real carrier credentials exist). REST v1, header
 * `Authorization: Token <api key>`; settings in config/services.php (`services.karrio`), key only in .env / vault.
 * Money: Karrio returns decimal amounts → integer cents here. Dimensions mm → cm, weights kg.
 */
final class KarrioAdapter implements CarrierAdapter
{
    public const SOURCE = 'karrio';

    /** Karrio `service_code` = "<connection carrier_id>::<service>" so book() can target the same connection. */
    private const CODE_SEPARATOR = '::';

    /** Shipment statuses that mean the label was bought (Karrio 2026.x reports `created`, older builds `purchased`). */
    private const BOOKED_STATUSES = ['purchased', 'created', 'in_transit', 'shipped', 'delivered'];

    public function __construct(private readonly HttpFactory $http) {}

    public function source(): string
    {
        return self::SOURCE;
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
            $response = $this->client()->post('v1/proxy/rates', $this->ratePayload($request));
        } catch (ConnectionException) {
            return [];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        $options = [];
        foreach ($response->json('rates') ?? [] as $rate) {
            if (! is_array($rate) || ! is_numeric($rate['total_charge'] ?? null)) {
                continue;
            }
            $costCents = (int) round((float) $rate['total_charge'] * 100, 0, PHP_ROUND_HALF_UP);
            if ($costCents < 1) {
                continue;
            }
            $service = (string) ($rate['service'] ?? 'standard');
            $transit = isset($rate['transit_days']) && is_numeric($rate['transit_days']) ? (int) $rate['transit_days'] : null;

            $options[] = [
                'service_code' => (string) ($rate['carrier_id'] ?? '').self::CODE_SEPARATOR.$service,
                'service_name' => trim(((string) ($rate['meta']['carrier_name'] ?? $rate['carrier_name'] ?? '')).' '.((string) ($rate['meta']['service_name'] ?? $service))),
                'service_level' => $this->serviceLevel($service, $transit),
                'cost_cents' => $costCents,
                'eta_days' => $transit,
                'pickup_dates' => array_values(array_filter([$request['requested_date'] ?? null])),
                'raw' => $rate + ['booking_id' => (string) ($rate['id'] ?? '')], // booking_id → TransportOptionService keeps it as _booking.quote_ref
            ];
        }

        return $options;
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        if (! $this->configured() || ! $this->completeParty($request['sender'] ?? []) || ! $this->completeParty($request['receiver'] ?? [])) {
            return $this->failedBooking('');
        }
        [$carrierId, $service] = $this->splitCode($serviceCode);

        try {
            // `service` buys the label in one call when the connection returns a matching rate.
            $created = $this->client()->post('v1/shipments', $this->ratePayload($request) + [
                'service' => $service,
                'carrier_ids' => $carrierId !== '' ? [$carrierId] : $this->carrierIds(),
                'label_type' => 'PDF',
                'payment' => ['paid_by' => 'sender', 'currency' => 'AUD'],
                'reference' => $this->reference($request),
                'metadata' => array_filter(['erp_quote_ref' => $options['quote_ref'] ?? null, 'erp_pickup_date' => $options['pickup_date'] ?? null]),
            ]);
            if (! $created->successful() || ! is_array($created->json())) {
                return $this->failedBooking('', ['create' => $created->json()]);
            }
            $shipment = $created->json();
            $shipmentId = (string) ($shipment['id'] ?? '');

            if (! $this->isBooked($shipment)) {
                $rate = collect($shipment['rates'] ?? [])->first(fn ($r) => is_array($r) && ($r['service'] ?? null) === $service && ($carrierId === '' || ($r['carrier_id'] ?? null) === $carrierId))
                    ?? collect($shipment['rates'] ?? [])->first();
                if ($shipmentId === '' || ! is_array($rate)) {
                    return $this->failedBooking($shipmentId, ['create' => $shipment]);
                }
                $purchased = $this->client()->post("v1/shipments/{$shipmentId}/purchase", ['selected_rate_id' => $rate['id'], 'label_type' => 'PDF', 'payment' => ['paid_by' => 'sender', 'currency' => 'AUD']]);
                if (! $purchased->successful() || ! is_array($purchased->json())) {
                    return $this->failedBooking($shipmentId, ['create' => $shipment, 'purchase' => $purchased->json()]);
                }
                $shipment = $purchased->json();
            }
        } catch (ConnectionException) {
            return $this->failedBooking('');
        }

        return [
            'booking_ref' => (string) ($shipment['id'] ?? ''),
            'tracking_number' => isset($shipment['tracking_number']) ? (string) $shipment['tracking_number'] : null,
            'label_path' => isset($shipment['label_url']) ? (string) $shipment['label_url'] : null,
            'status' => $this->isBooked($shipment) ? 'booked' : (string) ($shipment['status'] ?? 'request_failed'),
            'raw' => ['shipment' => $shipment],
        ];
    }

    public function cancel(string $bookingRef): bool
    {
        if (! $this->configured() || trim($bookingRef) === '') {
            return false;
        }

        try {
            return $this->client()->post("v1/shipments/{$bookingRef}/cancel")->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function label(string $bookingRef): ?string
    {
        $shipment = $this->shipment($bookingRef);
        $url = trim((string) ($shipment['label_url'] ?? ''));
        if ($url === '') {
            return null;
        }

        try {
            $response = $this->client()->accept('application/pdf')->get($this->absolute($url));
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    public function tracking(string $bookingRef): array
    {
        $shipment = $this->shipment($bookingRef);
        if ($shipment === []) {
            return [];
        }
        $trackerRef = trim((string) ($shipment['tracker_id'] ?? $shipment['tracking_number'] ?? ''));
        if ($trackerRef === '') {
            return [];
        }

        try {
            $response = $this->client()->get("v1/trackers/{$trackerRef}");
            if ($response->status() === 404 && filled($shipment['tracking_number'] ?? null)) {
                $response = $this->client()->post('v1/trackers', ['tracking_number' => (string) $shipment['tracking_number'], 'carrier_name' => (string) ($shipment['carrier_name'] ?? ''), 'reference' => $bookingRef]);
            }
        } catch (ConnectionException) {
            return [];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }
        $tracker = $response->json();

        $events = [];
        foreach ($tracker['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }
            $events[] = [
                'status' => (string) ($event['code'] ?: ($tracker['status'] ?? 'in_transit')),
                'description' => isset($event['description']) ? (string) $event['description'] : null,
                'location' => isset($event['location']) ? (string) $event['location'] : null,
                'occurred_at' => $this->occurredAt($event['date'] ?? null, $event['time'] ?? null),
                'raw' => $event,
            ];
        }
        if ($events === [] && ! in_array($tracker['status'] ?? 'unknown', ['unknown', 'pending'], true)) {
            // Carriers without an event feed (Karrio's custom carrier) still report a status — keep it as one event.
            $events[] = ['status' => (string) $tracker['status'], 'description' => null, 'location' => null, 'occurred_at' => now()->toIso8601String(), 'raw' => $tracker];
        }

        return $events;
    }

    /** @param array<string, mixed> $shipment */
    private function isBooked(array $shipment): bool
    {
        return in_array($shipment['status'] ?? '', self::BOOKED_STATUSES, true) || filled($shipment['tracking_number'] ?? null);
    }

    private function configured(): bool
    {
        return trim((string) config('services.karrio.api_key')) !== '';
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('services.karrio.base_url'), '/').'/')
            ->timeout(20)
            ->withHeaders(['Authorization' => 'Token '.trim((string) config('services.karrio.api_key'))])
            ->acceptJson();
    }

    /** @return list<string> */
    private function carrierIds(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('services.karrio.carrier_ids', '')))));
    }

    /** @return array<string, mixed> */
    private function shipment(string $bookingRef): array
    {
        if (! $this->configured() || trim($bookingRef) === '') {
            return [];
        }

        try {
            $response = $this->client()->get("v1/shipments/{$bookingRef}");
        } catch (ConnectionException) {
            return [];
        }

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    /** @return array<string, mixed> */
    private function ratePayload(array $request): array
    {
        $parcels = [];
        foreach ($request['items'] as $item) {
            $pieces = max(1, min(50, (int) ($item['qty'] ?? 1))); // Karrio parcels carry no quantity: one parcel per piece
            $isPallet = max((int) $item['length_mm'], (int) $item['width_mm']) >= 1000 || (float) $item['weight_kg'] >= 250;
            for ($i = 0; $i < $pieces; $i++) {
                $parcels[] = [
                    'weight' => (float) $item['weight_kg'],
                    'weight_unit' => 'KG',
                    'length' => round(((int) $item['length_mm']) / 10, 1),
                    'width' => round(((int) $item['width_mm']) / 10, 1),
                    'height' => round(((int) $item['height_mm']) / 10, 1),
                    'dimension_unit' => 'CM',
                    'packaging_type' => $isPallet ? 'pallet' : 'your_packaging',
                    'description' => (string) $item['description'],
                ];
            }
        }

        return [
            'shipper' => $this->party($request['sender']),
            'recipient' => $this->party($request['receiver']),
            'parcels' => $parcels,
            'carrier_ids' => $this->carrierIds(),
            'options' => array_filter([
                'currency' => 'AUD',
                'declared_value' => round(((int) ($request['declared_value_cents'] ?? 0)) / 100, 2),
                'shipment_date' => $request['requested_date'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'reference' => $this->reference($request),
        ];
    }

    /** @return array<string, mixed> */
    private function party(array $party): array
    {
        return array_filter([
            'person_name' => $party['name'] ?? null,
            'company_name' => $party['company_name'] ?? null,
            'phone_number' => $party['phone'] ?? null,
            'email' => $party['email'] ?? null,
            'address_line1' => $party['address'],
            'city' => $party['suburb'],
            'state_code' => $party['state'],
            'postal_code' => (string) $party['postcode'],
            'country_code' => 'AU',
            'residential' => ($party['type'] ?? 'business') === 'residential',
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @param array<string, mixed> $party */
    private function completeParty(array $party): bool
    {
        foreach (['address', 'suburb', 'state', 'postcode'] as $key) {
            if (trim((string) ($party[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function reference(array $request): string
    {
        return mb_substr((string) ($request['description'] ?? $request['tracking_number'] ?? 'ERP shipment'), 0, 35);
    }

    /** @return array{0: string, 1: string} carrier connection id, service */
    private function splitCode(string $serviceCode): array
    {
        $parts = explode(self::CODE_SEPARATOR, $serviceCode, 2);

        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : ['', trim($serviceCode)];
    }

    private function serviceLevel(string $service, ?int $transitDays): string
    {
        $service = strtolower($service);

        return match (true) {
            str_contains($service, 'same') || $transitDays === 0 => 'same_day',
            (bool) preg_match('/(express|priority|overnight|next_day|nextday|premium)/', $service) => 'express',
            default => 'standard',
        };
    }

    private function occurredAt(mixed $date, mixed $time): ?string
    {
        if (blank($date)) {
            return null;
        }
        try {
            return CarbonImmutable::parse(trim((string) $date.' '.(string) $time), 'Australia/Melbourne')->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') ? $url : rtrim((string) config('services.karrio.base_url'), '/').'/'.ltrim($url, '/');
    }

    /** @return array{booking_ref:string, tracking_number:null, label_path:null, status:string, raw:array<string, mixed>} */
    private function failedBooking(string $bookingRef, array $raw = []): array
    {
        return ['booking_ref' => $bookingRef, 'tracking_number' => null, 'label_path' => null, 'status' => 'request_failed', 'raw' => $raw];
    }
}
