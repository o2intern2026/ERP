<?php

namespace App\Modules\Transport\Adapters;

use App\Support\Contracts\CarrierAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * TEMPLATE — copy this file to <Carrier>Adapter.php and follow docs/carrier-integration.md (CHANGE_REQUESTS #163).
 *
 * It is NOT registered in TransportServiceProvider, so it never quotes anything in production; ExampleHttpCarrierAdapterTest runs it
 * against faked HTTP to show the whole cycle (quote → book → label → tracking → cancel) and proves the contract test passes.
 *
 * The imaginary carrier speaks JSON over HTTPS with a bearer token (config/services.php `example_carrier`):
 *   POST   {base}/v1/quotes                   → { "quotes": [ { "id", "service", "name", "price", "transit_days", "pickup_dates": [] } ] }
 *   POST   {base}/v1/consignments             → { "consignment_no", "tracking_number", "status", "label_url" }
 *   DELETE {base}/v1/consignments/{no}        → 204
 *   GET    {base}/v1/consignments/{no}/label  → PDF bytes
 *   GET    {base}/v1/consignments/{no}/events → { "events": [ { "status", "description", "location", "occurred_at" } ] }
 *
 * Conventions every adapter keeps (App\Support\Contracts\CarrierAdapter): money in integer cents, dimensions in mm, weights in kg;
 * "no quote" is an empty list — never an exception; a failed booking is a result whose status is one of
 * ShipmentBookingService::NOT_BOOKED_STATUSES ('request_failed' here); network errors (ConnectionException) are caught.
 */
final class ExampleHttpCarrierAdapter implements CarrierAdapter
{
    /** contracts/enums.md carrier_services.source — a real carrier adds its slug to TransportEnums::SOURCES and lang/zh/transport.php `sources`. */
    public const SOURCE = 'example';

    public function __construct(private readonly HttpFactory $http) {}

    public function source(): string
    {
        return self::SOURCE;
    }

    public function capabilities(): array
    {
        return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => true, 'tracking' => 'poll', 'pod' => 'manual'];
    }

    public function quote(array $request): array
    {
        if (! $this->configured() || ! $this->completeParty($request['sender'] ?? []) || ! $this->completeParty($request['receiver'] ?? [])) {
            return []; // nothing to quote is an empty list, never an exception
        }

        try {
            $response = $this->client()->post('v1/quotes', $this->payload($request));
        } catch (ConnectionException) {
            return [];
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        $options = [];
        foreach ($response->json('quotes') ?? [] as $quote) {
            if (! is_array($quote) || ! is_numeric($quote['price'] ?? null)) {
                continue;
            }
            $costCents = (int) round((float) $quote['price'] * 100, 0, PHP_ROUND_HALF_UP);
            if ($costCents < 1) {
                continue; // never quote $0 — a missing price is a Missing Rate, not a free delivery
            }
            $options[] = [
                'service_code' => (string) $quote['service'],                       // handed back to book() unchanged
                'service_name' => (string) ($quote['name'] ?? $quote['service']),
                'service_level' => $this->serviceLevel((string) $quote['service']),  // must match a carrier_services row of this source
                'cost_cents' => $costCents,                                          // the carrier's price to US, GST inclusive
                'eta_days' => isset($quote['transit_days']) && is_numeric($quote['transit_days']) ? (int) $quote['transit_days'] : null,
                'pickup_dates' => array_values(array_filter(array_map('strval', (array) ($quote['pickup_dates'] ?? [])))),
                'raw' => $quote + ['booking_id' => (string) ($quote['id'] ?? '')], // booking_id → _booking.quote_ref → book($options['quote_ref'])
            ];
        }

        return $options;
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        if (! $this->configured() || ! $this->completeParty($request['sender'] ?? []) || ! $this->completeParty($request['receiver'] ?? [])) {
            return $this->failed('');
        }

        try {
            $response = $this->client()->post('v1/consignments', $this->payload($request) + [
                'service' => $serviceCode,
                'quote_id' => $options['quote_ref'] ?? null,
                'pickup_date' => $options['pickup_date'] ?? null,
                'reference' => (string) ($request['description'] ?? ''), // the ERP shipment number, printed on the carrier's paperwork
            ]);
        } catch (ConnectionException) {
            return $this->failed('');
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return $this->failed('', ['response' => $response->json()]);
        }
        $body = $response->json();

        return [
            'booking_ref' => (string) ($body['consignment_no'] ?? ''),
            'tracking_number' => isset($body['tracking_number']) ? (string) $body['tracking_number'] : null,
            'label_path' => isset($body['label_url']) ? (string) $body['label_url'] : null,
            'status' => (string) ($body['status'] ?? 'request_failed'), // 'booked' / 'confirmed' = booked; NOT_BOOKED_STATUSES = refused
            'raw' => $body,
        ];
    }

    public function cancel(string $bookingRef): bool
    {
        try {
            return $this->client()->delete('v1/consignments/'.rawurlencode($bookingRef))->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function label(string $bookingRef): ?string
    {
        try {
            $response = $this->client()->get('v1/consignments/'.rawurlencode($bookingRef).'/label');
        } catch (ConnectionException) {
            return null;
        }
        $pdf = $response->successful() ? $response->body() : '';

        return str_starts_with($pdf, '%PDF-') ? $pdf : null; // ShipmentLabelService archives PDF bytes only
    }

    public function tracking(string $bookingRef): array
    {
        try {
            $response = $this->client()->get('v1/consignments/'.rawurlencode($bookingRef).'/events');
        } catch (ConnectionException) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        $events = [];
        foreach ($response->json('events') ?? [] as $event) {
            if (! is_array($event) || trim((string) ($event['status'] ?? '')) === '') {
                continue;
            }
            $events[] = [
                'status' => (string) $event['status'], // free text — TrackingSyncService maps it by keyword (delivered / in transit / picked up / failed …)
                'description' => isset($event['description']) ? (string) $event['description'] : null,
                'location' => isset($event['location']) ? (string) $event['location'] : null,
                'occurred_at' => isset($event['occurred_at']) ? (string) $event['occurred_at'] : null, // ISO 8601
                'raw' => $event,
            ];
        }

        return $events;
    }

    private function configured(): bool
    {
        return trim((string) config('services.example_carrier.api_key')) !== '';
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('services.example_carrier.base_url'), '/').'/')
            ->withToken(trim((string) config('services.example_carrier.api_key')))
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * ERP request → carrier payload. The ERP hands over mm / kg / cents; this carrier wants cm / kg / dollars — convert here, nowhere else.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function payload(array $request): array
    {
        $party = fn (array $p): array => [
            'name' => (string) ($p['company_name'] ?? $p['name'] ?? ''),
            'contact' => (string) ($p['name'] ?? ''),
            'phone' => (string) ($p['phone'] ?? ''),
            'email' => (string) ($p['email'] ?? ''),
            'address' => (string) ($p['address'] ?? ''),
            'suburb' => (string) ($p['suburb'] ?? ''),
            'state' => (string) ($p['state'] ?? ''),
            'postcode' => (string) ($p['postcode'] ?? ''),
            'residential' => ($p['type'] ?? 'business') === 'residential',
        ];
        $parcels = [];
        foreach ($request['items'] ?? [] as $item) {
            for ($i = 0; $i < max(1, (int) ($item['qty'] ?? 1)); $i++) { // one parcel per piece — most carriers price per piece
                $parcels[] = [
                    'weight_kg' => (float) $item['weight_kg'],
                    'length_cm' => (int) $item['length_mm'] / 10,
                    'width_cm' => (int) $item['width_mm'] / 10,
                    'height_cm' => (int) $item['height_mm'] / 10,
                    'description' => (string) ($item['description'] ?? 'carton'),
                ];
            }
        }

        return [
            'sender' => $party($request['sender'] ?? []),
            'receiver' => $party($request['receiver'] ?? []),
            'parcels' => $parcels,
            'declared_value' => round(((int) ($request['declared_value_cents'] ?? 0)) / 100, 2), // dollars as a float
            'tailgate_pickup' => (bool) ($request['tailgate_pickup'] ?? false),
            'tailgate_delivery' => (bool) ($request['tailgate_delivery'] ?? false),
            'ready_date' => $request['requested_date'] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $party */
    private function completeParty(array $party): bool
    {
        foreach (['address', 'suburb', 'state', 'postcode'] as $key) {
            if (trim((string) ($party[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** The carrier's service names → the ERP's three levels (TransportEnums::SERVICE_LEVELS), the only values a carrier_services row can carry. */
    private function serviceLevel(string $service): string
    {
        $service = strtolower($service);

        return match (true) {
            str_contains($service, 'same') => 'same_day',
            str_contains($service, 'express'), str_contains($service, 'priority') => 'express',
            default => 'standard',
        };
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{booking_ref:string, tracking_number:?string, label_path:?string, status:string, raw:array<string, mixed>}
     */
    private function failed(string $bookingRef, array $raw = []): array
    {
        return ['booking_ref' => $bookingRef, 'tracking_number' => null, 'label_path' => null, 'status' => 'request_failed', 'raw' => $raw];
    }
}
