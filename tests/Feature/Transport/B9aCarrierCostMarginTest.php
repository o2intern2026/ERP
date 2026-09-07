<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Models\CarrierCost;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\CarrierCostService;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\ShipmentBookingService;
use App\Modules\Transport\Services\ShipmentMarginService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use App\Support\Outbox\OutboxPublisher;
use App\Support\Tenancy\ClientScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B9aCarrierCostMarginTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function tearDown(): void
    {
        ClientScope::set(null);
        parent::tearDown();
    }

    public function test_carrier_cost_table_matches_contract_and_client_requests_cannot_query_costs(): void
    {
        $this->assertTrue(Schema::hasColumns('carrier_costs', [
            'shipment_id', 'job_id', 'carrier_id', 'expected_cost_cents', 'actual_cost_cents',
            'variance_cents', 'note', 'confirmed_at', 'created_at', 'updated_at',
        ]));
        $shipment = $this->shipment('transdirect', ['status' => 'booked']);
        CarrierCost::query()->create([
            'shipment_id' => $shipment->id,
            'job_id' => $shipment->job_id,
            'carrier_id' => $shipment->carrier_id,
            'expected_cost_cents' => 10000,
        ]);

        ClientScope::set($shipment->client_id);
        $this->assertSame(0, CarrierCost::query()->count());
        $summary = app(ShipmentMarginService::class)->shipment($shipment);
        $this->assertSame(12500, $summary['revenue_cents']);
        $this->assertArrayNotHasKey('expected_cost_cents', $summary);
        $this->assertArrayNotHasKey('actual_cost_cents', $summary);
        $this->assertArrayNotHasKey('margin_cents', $summary);
    }

    public function test_third_party_booking_records_quote_cost_and_exact_booked_event_once(): void
    {
        $operator = $this->staff('transport_operator');
        $shipment = $this->shipment('transdirect');
        $adapter = new B9aCarrierAdapter('transdirect', [
            'booking_ref' => 'TD-B9A-100',
            'tracking_number' => 'CON-B9A-100',
            'label_path' => 'https://example.test/label.pdf',
            'status' => 'confirmed',
            'raw' => ['status' => 'confirmed'],
        ]);
        $requests = Mockery::mock(ShipmentQuoteRequestFactory::class);
        $requests->shouldReceive('build')->once()->withArgs(fn (Shipment $value, string $stage): bool => $value->is($shipment) && $stage === 'final')->andReturn([
            'requested_date' => '2026-09-10',
        ]);
        $this->app->instance(ShipmentBookingService::class, $this->bookingService($adapter, $requests));

        $this->actingAs($operator)->post(route('transport.shipments.book', $shipment), [
            'pickup_date' => '2026-09-10',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.booking.booked'));

        $this->assertSame(1, $adapter->bookCalls);
        $this->assertSame('td_road', $adapter->lastServiceCode);
        $this->assertSame('TD-QUOTE-B9A', $adapter->lastOptions['quote_ref']);
        $this->assertSame('2026-09-10', $adapter->lastOptions['pickup_date']);
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'status' => 'booked',
            'booking_ref' => 'TD-B9A-100',
            'tracking_number' => 'CON-B9A-100',
        ]);
        $this->assertDatabaseHas('carrier_costs', [
            'shipment_id' => $shipment->id,
            'job_id' => $shipment->job_id,
            'carrier_id' => $shipment->carrier_id,
            'expected_cost_cents' => 10000,
            'actual_cost_cents' => null,
            'variance_cents' => null,
            'confirmed_at' => null,
        ]);

        $event = OutboxEvent::query()->where('event_name', 'shipment.booked')->sole();
        $this->assertSame($shipment->shipment_no, $event->correlation_id);
        $this->assertEqualsCanonicalizing([
            'shipment_id', 'shipment_no', 'job_id', 'client_id', 'order_id', 'carrier_id', 'source',
            'service_level', 'booking_ref', 'tracking_number', 'waybill_document_id', 'expected_cost_cents',
            'delivery_run_id', 'booked_at',
        ], array_keys($event->payload));
        $this->assertSame(10000, $event->payload['expected_cost_cents']);
        $this->assertSame('transdirect', $event->payload['source']);

        $this->actingAs($operator)->post(route('transport.shipments.book', $shipment));
        $this->assertSame(1, $adapter->bookCalls);
        $this->assertDatabaseCount('carrier_costs', 1);
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'shipment.booked')->count());
    }

    public function test_only_a_manual_financial_hold_blocks_booking(): void
    {
        $operator = $this->staff('dispatcher');
        $shipment = $this->shipment('manual');
        $adapter = new B9aCarrierAdapter('manual', [
            'booking_ref' => 'MAN-B9A-100',
            'tracking_number' => 'MAN-TRACK-100',
            'label_path' => null,
            'status' => 'booked_manually',
            'raw' => [],
        ]);
        $requests = Mockery::mock(ShipmentQuoteRequestFactory::class);
        $this->app->instance(ShipmentBookingService::class, $this->bookingService($adapter, $requests));
        $exceptions = app(ExceptionService::class);
        $holdId = $exceptions->raise('hold', 'orders', [
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'hold_type' => 'financial',
            'message' => 'Finance review',
        ]);

        $this->actingAs($operator)->post(route('transport.shipments.book', $shipment), [
            'booking_reference' => 'MAN-B9A-100',
            'tracking_number' => 'MAN-TRACK-100',
        ])->assertSessionHasErrors('booking');
        $this->assertSame(0, $adapter->bookCalls);
        $this->assertDatabaseMissing('carrier_costs', ['shipment_id' => $shipment->id]);

        $exceptions->resolve($holdId, $operator->id, 'Approved');
        $this->actingAs($operator)->post(route('transport.shipments.book', $shipment), [
            'booking_reference' => 'MAN-B9A-100',
            'tracking_number' => 'MAN-TRACK-100',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $adapter->bookCalls);
        $this->assertDatabaseHas('carrier_costs', [
            'shipment_id' => $shipment->id,
            'expected_cost_cents' => 10000,
        ]);
    }

    public function test_own_fleet_is_booked_when_assigned_and_its_cost_is_entered_manually(): void
    {
        $operator = $this->staff('transport_operator');
        $shipment = $this->shipment('own_fleet');
        $run = app(DeliveryRunService::class)->create('2026-09-10', $operator->id, 'VAN-B9A');

        $this->actingAs($operator)->post(route('transport.runs.stops.store', $run), [
            'shipment_id' => $shipment->id,
            'eta' => '2026-09-10 10:00:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame('booked', $shipment->fresh()->status);
        $this->assertSame($run->id, $shipment->fresh()->delivery_run_id);
        $this->assertDatabaseMissing('carrier_costs', ['shipment_id' => $shipment->id]);
        $this->assertSame($run->id, OutboxEvent::query()->where('event_name', 'shipment.booked')->sole()->payload['delivery_run_id']);

        $this->actingAs($operator)->post(route('transport.shipments.own-fleet-cost.store', $shipment), [
            'cost_cents' => 8300,
            'note' => 'Fuel and driver time',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.costs.saved'));
        $this->assertDatabaseHas('carrier_costs', [
            'shipment_id' => $shipment->id,
            'expected_cost_cents' => 8300,
            'actual_cost_cents' => 8300,
            'variance_cents' => 0,
            'note' => 'Fuel and driver time',
        ]);
        $this->assertNotNull($shipment->carrierCost()->sole()->confirmed_at);

        $this->actingAs($operator)->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(__('transport.costs.formula'))
            ->assertSee('$125.00')
            ->assertSee('$83.00')
            ->assertSee('$42.00');
    }

    public function test_order_margin_uses_actual_cost_when_known_and_expected_cost_until_then(): void
    {
        $finance = $this->staff('finance');
        $orderId = random_int(10000, 99999);
        $thirdParty = $this->shipment('transdirect', ['status' => 'booked', 'order_id' => $orderId]);
        $ownFleet = $this->shipment('own_fleet', ['status' => 'booked', 'order_id' => $orderId], 5000, 7500);
        CarrierCost::query()->create([
            'shipment_id' => $thirdParty->id,
            'job_id' => $thirdParty->job_id,
            'carrier_id' => $thirdParty->carrier_id,
            'expected_cost_cents' => 10000,
        ]);
        app(CarrierCostService::class)->recordOwnFleet($ownFleet, 5000, 'Own fleet actual');

        $summary = app(ShipmentMarginService::class)->order($orderId);
        $this->assertSame(20000, $summary['revenue_cents']);
        $this->assertSame(15000, $summary['payable_cost_cents']);
        $this->assertSame(5000, $summary['margin_cents']);
        $this->assertTrue($summary['margin_is_estimate']);

        $this->actingAs($finance)->get(route('transport.orders.margin', $orderId))
            ->assertOk()
            ->assertSee(__('transport.costs.formula'))
            ->assertSee('$200.00')
            ->assertSee('$150.00')
            ->assertSee('$50.00');
        $this->actingAs($this->clientUser())
            ->get(route('transport.orders.margin', $orderId))
            ->assertForbidden();
    }

    private function bookingService(B9aCarrierAdapter $adapter, ShipmentQuoteRequestFactory $requests): ShipmentBookingService
    {
        return new ShipmentBookingService(
            [$adapter],
            $requests,
            app(ExceptionService::class),
            app(CarrierCostService::class),
            app(OutboxPublisher::class),
        );
    }

    private function shipment(
        string $source,
        array $attributes = [],
        int $costCents = 10000,
        int $customerPriceCents = 12500,
    ): Shipment {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B9A-'.str()->upper(str()->random(8)),
            'name' => 'Carrier '.$source,
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B9A-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'quote_confirmed',
            'carrier_id' => $carrier->id,
            'service_level' => 'standard',
            'tailgate_required' => false,
        ]);
        $quote = TransportQuote::query()->create([
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => $source,
            'service_level' => 'standard',
            'cost_cents' => $costCents,
            'customer_price_cents' => $customerPriceCents,
            'markup_percent' => 25,
            'eta_days' => 2,
            'quote_stage' => 'final',
            'status' => 'selected',
            'selected_by' => 'system',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
            'raw_response' => [
                '_booking' => [
                    'service_code' => $source === 'transdirect' ? 'td_road' : $source.'.standard',
                    'quote_ref' => $source === 'transdirect' ? 'TD-QUOTE-B9A' : '',
                    'pickup_dates' => ['2026-09-10'],
                ],
            ],
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
    }
}

final class B9aCarrierAdapter implements CarrierAdapter
{
    public int $bookCalls = 0;

    public ?string $lastServiceCode = null;

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    /** @param array<string, mixed> $bookingResult */
    public function __construct(private readonly string $adapterSource, private readonly array $bookingResult) {}

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
        return [];
    }

    public function book(array $request, string $serviceCode, array $options = []): array
    {
        $this->bookCalls++;
        $this->lastServiceCode = $serviceCode;
        $this->lastOptions = $options;

        return $this->bookingResult;
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
}
