<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use App\Support\Contracts\RateService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B5dQuoteSelectionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_flags_respect_delivery_date_and_own_fleet_preference(): void
    {
        Carbon::setTestNow('2026-09-07 09:00:00');
        $shipment = $this->shipment();
        $own = $this->carrier('OWN-B5D');
        $manual = $this->carrier('MAN-B5D');
        $this->carrierService($own, 'own_fleet', 'standard');
        $this->carrierService($own, 'own_fleet', 'express');
        $this->carrierService($manual, 'manual', 'same_day');

        $factory = Mockery::mock(ShipmentQuoteRequestFactory::class);
        $factory->shouldReceive('build')->once()->andReturn($this->request('2026-09-10'));
        $rates = Mockery::mock(RateService::class);
        $rates->shouldReceive('thresholds')->once()->andReturn([
            'own_fleet_preference_percent' => 5,
            'variance_tolerance_percent' => 10,
        ]);

        $service = new TransportOptionService([
            $this->adapter('own_fleet', [
                $this->option('standard', 10000, 4),
                $this->option('express', 10700, 2),
            ]),
            $this->adapter('manual', [
                $this->option('same_day', 9000, 1, ['customer_price_cents' => 10500]),
            ]),
        ], $factory, $rates, app(ExceptionService::class));

        $quotes = collect($service->quote($shipment->id, 'final'))->keyBy('service_level');

        $this->assertTrue($quotes['standard']['is_cheapest']);
        $this->assertFalse($quotes['standard']['is_recommended'], 'The cheapest option misses the requested delivery date.');
        $this->assertTrue($quotes['same_day']['is_fastest']);
        $this->assertTrue($quotes['express']['is_recommended'], 'Own fleet is within the configured 5% preference.');
        $this->assertDatabaseHas('transport_quotes', ['service_level' => 'express', 'is_recommended' => true]);
    }

    public function test_coordinator_confirms_a_final_quote_and_emits_the_exact_event(): void
    {
        Carbon::setTestNow('2026-09-07 10:30:00');
        $shipment = $this->shipment(['status' => 'quoted', 'tailgate_required' => true]);
        $carrier = $this->carrier('TD-B5D');
        $quote = $this->quote($shipment, $carrier, [
            'source' => 'transdirect',
            'service_level' => 'express',
            'cost_cents' => 10000,
            'customer_price_cents' => 12500,
            'markup_percent' => 25,
            'eta_days' => 2,
            'raw_response' => [
                '_quote_request' => [
                    'zone' => 'remote',
                    'items' => [
                        ['qty' => 2, 'weight_kg' => 12.5, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
                        ['qty' => 1, 'weight_kg' => 350, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400],
                    ],
                ],
            ],
        ]);
        $coordinator = $this->staff('transport_operator');

        $this->actingAs($coordinator)
            ->post(route('transport.shipments.quotes.select', [$shipment, $quote]))
            ->assertRedirect()
            ->assertSessionHas('status', __('transport.selection.saved'));

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'status' => 'quote_confirmed',
            'selected_quote_id' => $quote->id,
            'carrier_id' => $carrier->id,
            'service_level' => 'express',
        ]);
        $this->assertDatabaseHas('transport_quotes', [
            'id' => $quote->id,
            'status' => 'selected',
            'selected_by' => 'coordinator',
            'selected_by_user_id' => $coordinator->id,
        ]);

        $event = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $this->assertSame($shipment->shipment_no, $event->correlation_id);
        $this->assertEqualsCanonicalizing([
            'shipment_id', 'shipment_no', 'shipment_type', 'job_id', 'client_id', 'order_id', 'fulfilment_id',
            'transport_quote_id', 'quote_stage', 'source', 'carrier_id', 'service_level', 'pricing_mode',
            'cost_cents', 'customer_price_cents', 'markup_percent', 'eta_days', 'tailgate_required', 'zone',
            'packages', 'confirmed_by_type', 'confirmed_by', 'confirmed_at',
        ], array_keys($event->payload));
        $this->assertSame('cost_plus', $event->payload['pricing_mode']);
        $this->assertSame(12500, $event->payload['customer_price_cents']);
        $this->assertTrue($event->payload['tailgate_required']);
        $this->assertSame('remote', $event->payload['zone']);
        $this->assertEqualsCanonicalizing(['count' => 3, 'total_weight_kg' => 375, 'total_cbm' => 2.076], $event->payload['packages']);
        $this->assertSame('coordinator', $event->payload['confirmed_by_type']);
        $this->assertSame($coordinator->id, $event->payload['confirmed_by']);
        $this->assertSame(now()->toIso8601String(), $event->payload['confirmed_at']);

        $this->actingAs($coordinator)
            ->post(route('transport.shipments.quotes.select', [$shipment, $quote]))
            ->assertRedirect();
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
    }

    public function test_client_selection_entry_point_enforces_client_tenancy(): void
    {
        $shipment = $this->shipment(['status' => 'quoted']);
        $quote = $this->quote($shipment, $this->carrier('OWN-CLIENT-B5D'));
        $wrongClient = $this->clientUser();

        try {
            app(QuoteSelectionService::class)->select($shipment, $quote, 'client', $wrongClient->id);
            $this->fail('A client from another tenant selected the quote.');
        } catch (DomainException) {
            $this->assertSame('quoted', $quote->fresh()->status);
        }

        $clientUser = $this->clientUser(Client::query()->withoutGlobalScopes()->findOrFail($shipment->client_id));
        app(QuoteSelectionService::class)->select($shipment, $quote, 'client', $clientUser->id);

        $this->assertSame('quote_confirmed', $shipment->fresh()->status);
        $this->assertSame('client', $quote->fresh()->selected_by);
        $this->assertSame('client', OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole()->payload['confirmed_by_type']);
        $this->actingAs($clientUser)
            ->get(route('transport.shipments.show', $shipment))
            ->assertForbidden();
    }

    public function test_final_quote_above_tolerance_waits_for_reconfirmation(): void
    {
        Carbon::setTestNow('2026-09-07 09:00:00');
        $shipment = $this->shipment(['status' => 'quoted']);
        $carrier = $this->carrier('OWN-VAR-B5D');
        $preliminary = $this->quote($shipment, $carrier, [
            'quote_stage' => 'preliminary',
            'customer_price_cents' => 10000,
            'cost_cents' => 10000,
        ]);
        $coordinator = $this->staff('transport_operator');
        app(QuoteSelectionService::class)->select($shipment, $preliminary, 'coordinator', $coordinator->id);
        $this->carrierService($carrier, 'own_fleet', 'standard');

        $service = $this->finalQuoteService($shipment, 12000, 10);
        $quotes = $service->quote($shipment->id, 'final');

        $this->assertTrue($quotes[0]['is_recommended']);
        $this->assertSame('quoted', $shipment->fresh()->status);
        $this->assertNull($shipment->fresh()->selected_quote_id);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'shipment.quote_confirmed']);
    }

    public function test_final_quote_within_tolerance_uses_system_confirmation(): void
    {
        Carbon::setTestNow('2026-09-07 09:00:00');
        $shipment = $this->shipment(['status' => 'quoted']);
        $carrier = $this->carrier('OWN-AUTO-B5D');
        $preliminary = $this->quote($shipment, $carrier, [
            'quote_stage' => 'preliminary',
            'customer_price_cents' => 10000,
            'cost_cents' => 10000,
        ]);
        $coordinator = $this->staff('transport_operator');
        app(QuoteSelectionService::class)->select($shipment, $preliminary, 'coordinator', $coordinator->id);
        $this->carrierService($carrier, 'own_fleet', 'standard');

        $service = $this->finalQuoteService($shipment, 10900, 10);
        $service->quote($shipment->id, 'final');

        $final = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->sole();
        $this->assertSame('selected', $final->status);
        $this->assertSame('system', $final->selected_by);
        $this->assertSame('quote_confirmed', $shipment->fresh()->status);
        $this->assertSame('system', OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole()->payload['confirmed_by_type']);
    }

    private function finalQuoteService(Shipment $shipment, int $price, float $tolerance): TransportOptionService
    {
        $factory = Mockery::mock(ShipmentQuoteRequestFactory::class);
        $factory->shouldReceive('build')->once()->andReturn($this->request());
        $rates = Mockery::mock(RateService::class);
        $rates->shouldReceive('thresholds')->twice()->andReturn([
            'own_fleet_preference_percent' => 0,
            'variance_tolerance_percent' => $tolerance,
        ]);

        return new TransportOptionService([
            $this->adapter('own_fleet', [$this->option('standard', $price, 1)]),
        ], $factory, $rates, app(ExceptionService::class), app(QuoteSelectionService::class));
    }

    private function shipment(array $attributes = []): Shipment
    {
        $this->actingAs($this->staff('transport_operator'));
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');

        return Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B5D-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'quoting',
            'tailgate_required' => false,
        ]);
    }

    private function quote(Shipment $shipment, Carrier $carrier, array $attributes = []): TransportQuote
    {
        return TransportQuote::query()->create($attributes + [
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => 'own_fleet',
            'service_level' => 'standard',
            'cost_cents' => 7500,
            'customer_price_cents' => 7500,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'quoted',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
            'raw_response' => [
                'pricing_mode' => 'fixed',
                '_quote_request' => ['zone' => 'metro', 'items' => []],
            ],
        ]);
    }

    private function carrier(string $code): Carrier
    {
        return Carrier::query()->create(['code' => $code, 'name' => $code, 'status' => 'active']);
    }

    private function carrierService(Carrier $carrier, string $source, string $level): CarrierService
    {
        return CarrierService::query()->create([
            'carrier_id' => $carrier->id,
            'source' => $source,
            'service_level' => $level,
            'default_eta_days' => 1,
            'active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function request(?string $requestedDate = null): array
    {
        return [
            'client_id' => 1,
            'sender' => ['address' => '1 Start St', 'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'type' => 'business'],
            'receiver' => ['address' => '2 End St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
            'items' => [['description' => 'Carton', 'qty' => 1, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
            'declared_value_cents' => 10000,
            'tailgate_pickup' => false,
            'tailgate_delivery' => false,
            'requested_date' => $requestedDate,
            'zone' => 'metro',
        ];
    }

    /** @return array<string, mixed> */
    private function option(string $level, int $cost, int $eta, array $raw = []): array
    {
        return [
            'service_code' => 'test.'.$level,
            'service_name' => $level,
            'service_level' => $level,
            'cost_cents' => $cost,
            'eta_days' => $eta,
            'pickup_dates' => [],
            'raw' => $raw,
        ];
    }

    /** @param list<array<string, mixed>> $quotes */
    private function adapter(string $source, array $quotes): CarrierAdapter
    {
        return new class($source, $quotes) implements CarrierAdapter
        {
            public function __construct(private readonly string $sourceName, private readonly array $quotes) {}

            public function source(): string
            {
                return $this->sourceName;
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
