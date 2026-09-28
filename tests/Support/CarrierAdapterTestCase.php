<?php

namespace Tests\Support;

use App\Modules\Transport\Support\TransportEnums;
use App\Support\Contracts\CarrierAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The contract every CarrierAdapter must keep (docs/carrier-integration.md, CHANGE_REQUESTS #163). A new carrier's test extends this
 * class, returns its adapter from adapter() and fakes the carrier's HTTP in the fake*() hooks; the shape checks below then run against it.
 * Adapters without HTTP (manual, own_fleet) extend it too and leave the hooks empty.
 */
abstract class CarrierAdapterTestCase extends TestCase
{
    abstract protected function adapter(): CarrierAdapter;

    /** Fake the carrier so quote() has something to return (Http::fake([...])); leave empty for adapters that need no HTTP. */
    protected function fakeCarrierForQuote(): void {}

    protected function fakeCarrierForBooking(): void {}

    protected function fakeCarrierForLabel(): void {}

    protected function fakeCarrierForTracking(): void {}

    protected function fakeCarrierForCancel(): void {}

    /** A complete delivery request the way ShipmentQuoteRequestFactory::build() hands it over: Dandenong South → Sydney, two cartons + one pallet. */
    protected function request(): array
    {
        return [
            'client_id' => 1,
            'sender' => ['name' => 'Warehouse', 'company_name' => 'Melbourne DC', 'phone' => '0390000000', 'email' => 'ops@example.test', 'address' => '1 Depot Rd', 'suburb' => 'Dandenong South', 'state' => 'VIC', 'postcode' => '3175', 'type' => 'business'],
            'receiver' => ['name' => 'Receiver', 'company_name' => 'Receiver Pty Ltd', 'phone' => '0290000000', 'email' => 'dock@example.test', 'address' => '10 Test St', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015', 'type' => 'business'],
            'items' => [
                ['description' => 'carton', 'qty' => 2, 'weight_kg' => 12.5, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
                ['description' => 'pallet', 'qty' => 1, 'weight_kg' => 320.0, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1400],
            ],
            'declared_value_cents' => 10000,
            'description' => 'SHP-TEST-0001',
            'tailgate_pickup' => false,
            'tailgate_delivery' => true,
            'requested_date' => '2026-09-08',
            'zone' => '2015',
        ];
    }

    public function test_source_is_a_lowercase_slug(): void
    {
        $source = $this->adapter()->source();
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{1,30}$/', $source, 'source() is the carrier_services.source slug');
    }

    public function test_capabilities_carry_every_key_with_an_allowed_value(): void
    {
        $caps = $this->adapter()->capabilities();
        $this->assertSame(['quote', 'book', 'cancel', 'label', 'tracking', 'pod'], array_keys($caps));
        foreach (['quote', 'book', 'cancel', 'label'] as $key) {
            $this->assertIsBool($caps[$key], "capabilities()[$key] is a bool");
        }
        $this->assertContains($caps['tracking'], ['poll', 'webhook', 'none']);
        $this->assertContains($caps['pod'], ['api', 'manual']);
    }

    public function test_quote_returns_well_formed_options(): void
    {
        $this->fakeCarrierForQuote();
        $options = $this->adapter()->quote($this->request());
        $this->assertIsList($options);
        if (! $this->adapter()->capabilities()['quote']) {
            $this->assertSame([], $options, 'an adapter that cannot quote returns an empty list');
        }
        foreach ($options as $option) {
            $this->assertSame(['service_code', 'service_name', 'service_level', 'cost_cents', 'eta_days', 'pickup_dates', 'raw'], array_keys($option));
            $this->assertIsString($option['service_code']);
            $this->assertNotSame('', $option['service_code']);
            $this->assertIsString($option['service_name']);
            $this->assertContains($option['service_level'], TransportEnums::SERVICE_LEVELS);
            $this->assertIsInt($option['cost_cents']);
            $this->assertGreaterThanOrEqual(1, $option['cost_cents'], 'never quote $0 — a missing price is a Missing Rate');
            $this->assertTrue($option['eta_days'] === null || is_int($option['eta_days']));
            $this->assertIsList($option['pickup_dates']);
            foreach ($option['pickup_dates'] as $date) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', (string) $date, 'pickup dates are ISO dates');
            }
            $this->assertIsArray($option['raw']);
        }
    }

    public function test_book_returns_the_contract_shape(): void
    {
        if (! $this->adapter()->capabilities()['book']) {
            $this->markTestSkipped('adapter cannot book');
        }
        $this->fakeCarrierForQuote();
        $first = $this->adapter()->quote($this->request())[0] ?? null;
        $this->fakeCarrierForBooking();
        $result = $this->adapter()->book($this->request(), $first['service_code'] ?? 'standard', [
            'quote_ref' => (string) ($first['raw']['booking_id'] ?? 'Q-TEST'),
            'pickup_date' => '2026-09-08',
        ]);
        $this->assertSame(['booking_ref', 'tracking_number', 'label_path', 'status', 'raw'], array_keys($result));
        $this->assertIsString($result['booking_ref']);
        $this->assertTrue($result['tracking_number'] === null || is_string($result['tracking_number']));
        $this->assertTrue($result['label_path'] === null || is_string($result['label_path']));
        $this->assertIsString($result['status']);
        $this->assertNotSame('', $result['status'], "status tells booked from refused (ShipmentBookingService::NOT_BOOKED_STATUSES) — '' means unknown");
        $this->assertIsArray($result['raw']);
    }

    public function test_tracking_returns_well_formed_events_when_the_adapter_polls(): void
    {
        if ($this->adapter()->capabilities()['tracking'] !== 'poll') {
            $this->assertSame([], $this->adapter()->tracking('REF-1'), "tracking() is an empty list for sources with tracking 'none' / 'webhook'");

            return;
        }
        $this->fakeCarrierForTracking();
        $events = $this->adapter()->tracking('REF-1');
        $this->assertIsList($events);
        foreach ($events as $event) {
            $this->assertSame(['status', 'description', 'location', 'occurred_at', 'raw'], array_keys($event));
            $this->assertIsString($event['status']);
            $this->assertNotSame('', trim($event['status']));
            foreach (['description', 'location', 'occurred_at'] as $key) {
                $this->assertTrue($event[$key] === null || is_string($event[$key]), "$key is a string or null");
            }
            $this->assertIsArray($event['raw']);
        }
    }

    public function test_label_is_pdf_bytes_or_null(): void
    {
        if (! $this->adapter()->capabilities()['label']) {
            $this->assertNull($this->adapter()->label('REF-1'));

            return;
        }
        $this->fakeCarrierForLabel();
        $pdf = $this->adapter()->label('REF-1');
        $this->assertTrue($pdf === null || str_starts_with($pdf, '%PDF-'), 'label() returns PDF bytes or null');
    }

    public function test_cancel_returns_a_bool(): void
    {
        $this->fakeCarrierForCancel();
        $this->assertIsBool($this->adapter()->cancel('REF-1'));
    }

    public function test_an_unreachable_carrier_means_no_quote_and_a_refused_booking_never_an_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('carrier down'));
        $this->assertIsList($this->adapter()->quote($this->request()));
        if ($this->adapter()->capabilities()['book']) {
            try {
                $result = $this->adapter()->book($this->request(), 'standard');
                $this->assertIsString($result['status']);
            } catch (InvalidArgumentException $e) {
                // Allowed: a call that can never succeed (e.g. a manual booking without its reference) — ShipmentBookingService reports the message as the reason.
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertIsList($this->adapter()->tracking('REF-1'));
        $this->assertIsBool($this->adapter()->cancel('REF-1'));
    }
}
