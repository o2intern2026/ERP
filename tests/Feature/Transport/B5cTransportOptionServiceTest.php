<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Transport\Adapters\ManualCarrierAdapter;
use App\Modules\Transport\Adapters\OwnFleetCarrierAdapter;
use App\Modules\Transport\Adapters\TransdirectAdapter;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use App\Support\Contracts\RateService;
use App\Support\Fakes\FakeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B5cTransportOptionServiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_carrier_services_table_matches_the_transport_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('carrier_services', [
            'carrier_id', 'source', 'service_level', 'default_eta_days', 'active', 'config',
        ]));
    }

    public function test_service_asks_each_active_source_and_persists_qualified_quotes(): void
    {
        [$shipment, $carrier] = $this->shipment();
        $transdirect = Carrier::query()->create(['code' => 'TD-B5C', 'name' => 'Transdirect', 'status' => 'active']);
        $this->carrierService($carrier, 'own_fleet', 'standard', 1);
        $this->carrierService($transdirect, 'transdirect', 'express', 2);

        $old = TransportQuote::query()->create($this->quoteAttributes($shipment, $carrier));
        $factory = Mockery::mock(ShipmentQuoteRequestFactory::class);
        $factory->shouldReceive('build')->once()->withArgs(
            fn (Shipment $value, string $stage): bool => $value->is($shipment) && $stage === 'final'
        )->andReturn($this->request());

        $service = new TransportOptionService([
            $this->adapter('own_fleet', [[
                'service_code' => 'own.standard', 'service_name' => 'Own fleet', 'service_level' => 'standard',
                'cost_cents' => 7500, 'eta_days' => 1, 'pickup_dates' => [], 'raw' => ['pricing_mode' => 'fixed'],
            ]]),
            $this->adapter('transdirect', [[
                'service_code' => 'toll_priority', 'service_name' => 'Toll Priority', 'service_level' => 'express',
                'cost_cents' => 10000, 'eta_days' => 2, 'pickup_dates' => ['2026-09-08'],
                'raw' => ['booking_id' => 'TD-100'],
            ]]),
        ], $factory, app(RateService::class), app(ExceptionService::class));

        $quotes = $service->quote($shipment->id, 'final');

        $this->assertCount(2, $quotes);
        $this->assertSame(['own_fleet', 'transdirect'], array_column($quotes, 'source'));
        $this->assertSame(7500, $quotes[0]['customer_price_cents']);
        $this->assertSame(12000, $quotes[1]['customer_price_cents']);
        $savedTransdirect = TransportQuote::query()->where('source', 'transdirect')->latest('id')->firstOrFail();
        $this->assertSame(20.0, (float) $savedTransdirect->markup_percent);
        $this->assertSame('TD-100', $savedTransdirect->raw_response['booking_id']);
        $this->assertEqualsCanonicalizing([
            'service_code' => 'toll_priority',
            'quote_ref' => 'TD-100',
            'pickup_dates' => ['2026-09-08'],
        ], $savedTransdirect->raw_response['_booking']);
        $this->assertSame('requoted', $old->fresh()->status);
        $this->assertSame('quoted', $shipment->fresh()->status);
    }

    public function test_no_automatic_option_raises_manual_transport_exception_without_throwing(): void
    {
        [$shipment, $carrier] = $this->shipment();
        $this->carrierService($carrier, 'manual', 'standard', 2);
        $factory = Mockery::mock(ShipmentQuoteRequestFactory::class);
        $factory->shouldReceive('build')->once()->andReturn($this->request());
        $service = new TransportOptionService(
            [$this->adapter('manual', [])],
            $factory,
            app(RateService::class),
            app(ExceptionService::class),
        );

        $this->assertSame([], $service->quote($shipment->id, 'final'));

        $exception = ExceptionRecord::query()->withoutGlobalScopes()->sole();
        $this->assertSame('manual_transport', $exception->type);
        $this->assertSame('transport', $exception->source_module);
        $this->assertSame('shipment', $exception->source_type);
        $this->assertSame($shipment->id, $exception->source_id);
    }

    public function test_own_fleet_and_manual_adapters_follow_the_frozen_interface(): void
    {
        $rates = app(RateService::class);
        $this->assertInstanceOf(FakeRateService::class, $rates);
        $rates->withRate('TR-DELIVERY-BASE', 7500);

        $ownFleet = new OwnFleetCarrierAdapter($rates);
        $ownQuote = $ownFleet->quote($this->request());
        $this->assertSame('own_fleet', $ownFleet->source());
        $this->assertSame(7500, $ownQuote[0]['cost_cents']);
        $this->assertSame('manual', $ownFleet->capabilities()['pod']);

        $manual = new ManualCarrierAdapter;
        $request = $this->request() + [
            'tracking_number' => 'HUMAN-TRACK-1',
            'manual_quotes' => [[
                'service_code' => 'manual.standard',
                'service_level' => 'standard',
                'cost_cents' => 8000,
                'customer_price_cents' => 9600,
                'eta_days' => 2,
            ]],
        ];
        $this->assertSame(8000, $manual->quote($request)[0]['cost_cents']);
        $booking = $manual->book($request, 'manual.standard', ['quote_ref' => 'MAN-100']);
        $this->assertSame('MAN-100', $booking['booking_ref']);
        $this->assertSame('HUMAN-TRACK-1', $booking['tracking_number']);
        $this->assertSame('booked_manually', $booking['status']);
        $this->assertNull($manual->label('MAN-100'));
        $this->assertSame([], $manual->tracking('MAN-100'));
    }

    public function test_transdirect_adapter_uses_v4_contract_and_parses_tracking_html(): void
    {
        config()->set('services.transdirect.api_key', 'test-api-key');
        config()->set('services.transdirect.base_url', 'https://transdirect.test/api');

        Http::fake(function (Request $request) {
            $url = $request->url();

            if ($request->method() === 'POST' && str_ends_with($url, '/bookings/v4')) {
                return Http::response([
                    'id' => 'TD-200',
                    'quotes' => [
                        'northline' => ['total' => 123.45, 'service' => 'road', 'transit_time' => '1-3 days', 'pickup_dates' => ['2026-09-08']],
                        'toll_priority_overnight' => ['total' => 180, 'service' => 'overnight', 'transit_time' => '1 day', 'pickup_dates' => ['2026-09-08']],
                    ],
                ], 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($url, '/bookings/v4/TD-200')) {
                return Http::response(['id' => 'TD-200'], 200);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/bookings/v4/TD-200/confirm')) {
                return Http::response(null, 204);
            }

            if ($request->method() === 'GET' && str_ends_with($url, '/bookings/v4/TD-200')) {
                return Http::response([
                    'id' => 'TD-200', 'connote' => 'IRE000623630',
                    'label' => 'https://transdirect.test/labels/TD-200.pdf', 'status' => 'demo',
                ]);
            }

            if ($request->method() === 'GET' && str_ends_with($url, '/bookings/v4/TD-200/label')) {
                return Http::response('%PDF-demo', 200, ['Content-Type' => 'application/pdf']);
            }

            if ($request->method() === 'GET' && str_ends_with($url, '/bookings/track/v4/TD-200')) {
                return Http::response('<table><tr><th>Status</th><th>Date</th><th>Time</th><th>Depot</th></tr><tr><td>In Transit</td><td>07/09/2026</td><td>10:30</td><td>Melbourne</td></tr></table>');
            }

            if ($request->method() === 'DELETE' && str_ends_with($url, '/bookings/v4/TD-200')) {
                return Http::response(null, 204);
            }

            return Http::response(['unexpected' => [$request->method(), $url]], 500);
        });

        $adapter = new TransdirectAdapter(app(HttpFactory::class));
        $quotes = $adapter->quote($this->request());
        $this->assertCount(2, $quotes);
        $this->assertSame(12345, $quotes[0]['cost_cents']);
        $this->assertSame(3, $quotes[0]['eta_days']);
        $this->assertSame('express', $quotes[1]['service_level']);
        $this->assertSame('TD-200', $quotes[0]['raw']['booking_id']);

        $booking = $adapter->book($this->request(), 'northline', [
            'quote_ref' => 'TD-200', 'pickup_date' => '2026-09-08',
        ]);
        $this->assertSame('IRE000623630', $booking['tracking_number']);
        $this->assertSame('https://transdirect.test/labels/TD-200.pdf', $booking['label_path']);
        $this->assertSame('demo', $booking['status']);
        $this->assertSame('%PDF-demo', $adapter->label('TD-200'));
        $this->assertSame('In Transit', $adapter->tracking('TD-200')[0]['status']);
        $this->assertTrue($adapter->cancel('TD-200'));
        $this->assertSame('poll', $adapter->capabilities()['tracking']);
        $this->assertSame('manual', $adapter->capabilities()['pod']);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/bookings/v4')) {
                return false;
            }

            return $request->hasHeader('Api-key', 'test-api-key')
                && $request['items'][0]['length'] === 40.0
                && $request['declared_value'] === 100.0
                && $request['tailgate_delivery'] === true;
        });
    }

    /** @return array{Shipment, Carrier} */
    private function shipment(): array
    {
        $this->actingAs($this->staff('transport_operator'));
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create(['code' => 'OWN-B5C', 'name' => 'Own fleet', 'status' => 'active']);

        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-B5C-'.str()->upper(str()->random(6)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(1000, 9999),
            'shipment_type' => 'outbound',
            'status' => 'quoting',
            'tailgate_required' => true,
        ]);

        return [$shipment, $carrier];
    }

    private function carrierService(Carrier $carrier, string $source, string $level, ?int $eta): CarrierService
    {
        return CarrierService::query()->create([
            'carrier_id' => $carrier->id,
            'source' => $source,
            'service_level' => $level,
            'default_eta_days' => $eta,
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'client_id' => 1,
            'sender' => ['name' => 'Sender', 'address' => '1 Start St', 'suburb' => 'Dandenong South', 'state' => 'VIC', 'postcode' => '3175', 'type' => 'business'],
            'receiver' => ['name' => 'Receiver', 'address' => '2 End St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
            'items' => [['description' => 'Carton', 'qty' => 2, 'weight_kg' => 12.5, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
            'declared_value_cents' => 10000,
            'tailgate_pickup' => false,
            'tailgate_delivery' => true,
            'requested_date' => '2026-09-08',
        ];
    }

    /** @return array<string, mixed> */
    private function quoteAttributes(Shipment $shipment, Carrier $carrier): array
    {
        return [
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => 'own_fleet',
            'service_level' => 'standard',
            'cost_cents' => 7000,
            'customer_price_cents' => 7000,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'quoted',
            'quoted_at' => now()->subHour(),
            'expires_at' => now()->addHours(23),
        ];
    }

    /** @param list<array<string, mixed>> $quotes */
    private function adapter(string $source, array $quotes): CarrierAdapter
    {
        return new class($source, $quotes) implements CarrierAdapter
        {
            public function __construct(private readonly string $adapterSource, private readonly array $quotes) {}

            public function source(): string
            {
                return $this->adapterSource;
            }

            public function capabilities(): array
            {
                return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => false, 'tracking' => 'none', 'pod' => 'manual'];
            }

            public function quote(array $request): array
            {
                return $this->quotes;
            }

            public function book(array $request, string $serviceCode, array $options = []): array
            {
                return ['booking_ref' => 'TEST', 'tracking_number' => null, 'label_path' => null, 'status' => 'confirmed', 'raw' => []];
            }

            public function cancel(string $bookingRef): bool
            {
                return true;
            }

            public function label(string $bookingRef): ?string
            {
                return null;
            }

            public function tracking(string $bookingRef): array
            {
                return [];
            }
        };
    }
}
