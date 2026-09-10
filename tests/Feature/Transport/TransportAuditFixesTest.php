<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** 2026-09-10 audit, lane B package B4 — the confirmed Transport findings. */
class TransportAuditFixesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_transport_nav_offers_only_the_links_the_role_may_open(): void
    {
        $runs = route('transport.runs.index');
        $reconciliation = route('transport.carrier-invoices.index');
        $driver = route('transport.driver');

        $this->actingAs($this->staff('finance'))->get(route('transport.index'))
            ->assertOk()
            ->assertSee($reconciliation)
            ->assertDontSee($runs)
            ->assertDontSee($driver);

        $this->actingAs($this->staff('dispatcher'))->get(route('transport.index'))
            ->assertOk()
            ->assertSee($runs)
            ->assertDontSee($reconciliation)
            ->assertDontSee($driver);

        $this->actingAs($this->staff('warehouse_operator'))->get(route('transport.index'))
            ->assertOk()
            ->assertSee(route('transport.index'))
            ->assertDontSee($runs)
            ->assertDontSee($reconciliation)
            ->assertDontSee($driver);

        $this->actingAs($this->staff('transport_operator'))->get(route('transport.index'))
            ->assertOk()
            ->assertSee($runs)
            ->assertSee($reconciliation)
            ->assertSee($driver);
    }

    public function test_shipment_page_hides_the_action_forms_from_roles_the_controllers_refuse(): void
    {
        $shipment = $this->shipment('manual', ['status' => 'quote_confirmed']);
        $this->carrierService($shipment->carrier, 'manual', 'standard');

        $finance = $this->staff('finance');
        $this->actingAs($finance)->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertDontSee(route('transport.shipments.extra-charges.store', $shipment))
            ->assertDontSee(route('transport.shipments.book', $shipment))
            ->assertDontSee(route('transport.shipments.pod.store', $shipment));
        $this->actingAs($finance)
            ->post(route('transport.shipments.extra-charges.store', $shipment), ['charge_type' => 'waiting', 'qty' => 1, 'uom' => 'delivery', 'note' => 'x'])
            ->assertForbidden();

        $this->actingAs($this->staff('transport_operator'))->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(route('transport.shipments.extra-charges.store', $shipment))
            ->assertSee(route('transport.shipments.book', $shipment))
            ->assertSee(route('transport.shipments.pod.store', $shipment));

        $ownFleet = $this->shipment('own_fleet', ['status' => 'booked']);
        $this->actingAs($this->staff('customer_service'))->get(route('transport.shipments.show', $ownFleet))
            ->assertOk()
            ->assertDontSee(route('transport.shipments.own-fleet-cost.store', $ownFleet));
        $this->actingAs($finance)->get(route('transport.shipments.show', $ownFleet))
            ->assertOk()
            ->assertSee(route('transport.shipments.own-fleet-cost.store', $ownFleet));
    }

    public function test_print_button_is_only_offered_when_a_label_can_be_produced_and_failures_stay_on_the_page(): void
    {
        Storage::fake('local');
        $operator = $this->staff('transport_operator');

        $manual = $this->shipment('manual', ['status' => 'booked', 'booking_ref' => 'MANUAL-SHP-0001']);
        $this->actingAs($operator)->get(route('transport.shipments.show', $manual))
            ->assertOk()
            ->assertDontSee(route('transport.shipments.label', $manual))
            ->assertSee(__('transport.labels.manual_unavailable'));
        $this->actingAs($operator)->get(route('transport.shipments.label', $manual))
            ->assertRedirect(route('transport.shipments.show', $manual))
            ->assertSessionHasErrors(['label' => __('transport.labels.waybill_unavailable')]);

        $preliminary = $this->shipment('own_fleet', ['status' => 'quoted'], ['quote_stage' => 'preliminary']);
        $this->actingAs($operator)->get(route('transport.shipments.show', $preliminary))
            ->assertOk()
            ->assertDontSee(route('transport.shipments.label', $preliminary))
            ->assertSee(__('transport.labels.not_ready'));

        $ownFleet = $this->shipment('own_fleet');
        $this->actingAs($operator)->get(route('transport.shipments.show', $ownFleet))
            ->assertOk()
            ->assertSee(route('transport.shipments.label', $ownFleet));
        $this->actingAs($this->staff('finance'))->get(route('transport.shipments.show', $ownFleet))
            ->assertOk()
            ->assertDontSee(route('transport.shipments.label', $ownFleet));
    }

    public function test_manual_quote_with_a_markup_above_the_column_limit_is_refused_in_chinese_with_input_kept(): void
    {
        $shipment = $this->shipment('manual', ['status' => 'quoting'], selected: false);
        $service = $this->carrierService($shipment->carrier, 'manual', 'standard');
        $this->mock(ShipmentQuoteRequestFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturn($this->request());
        });

        $this->actingAs($this->staff('dispatcher'))
            ->from(route('transport.shipments.show', $shipment))
            ->post(route('transport.shipments.quotes.manual', $shipment), [
                'carrier_service_id' => $service->id,
                'quote_stage' => 'final',
                'cost_cents' => 5000,
                'customer_price_cents' => 60000,
                'eta_days' => 3,
            ])
            ->assertRedirect(route('transport.shipments.show', $shipment))
            ->assertSessionHasErrors(['manual_quote' => __('transport.manual_quote.markup_too_high')])
            ->assertSessionHasInput('cost_cents', '5000')
            ->assertSessionHasInput('customer_price_cents', '60000');
        $this->assertDatabaseCount('transport_quotes', 0);

        $this->actingAs($this->staff('dispatcher'))
            ->post(route('transport.shipments.quotes.manual', $shipment), [
                'carrier_service_id' => $service->id,
                'quote_stage' => 'final',
                'cost_cents' => 5000,
                'customer_price_cents' => 54999,
                'eta_days' => 3,
            ])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('transport_quotes', ['shipment_id' => $shipment->id, 'markup_percent' => 999.98]);
    }

    public function test_manual_quote_form_explains_instead_of_failing_when_the_booking_request_cannot_be_built(): void
    {
        $shipment = $this->shipment('manual', ['status' => 'quoting'], selected: false);
        $this->carrierService($shipment->carrier, 'manual', 'standard');
        $operator = $this->staff('transport_operator');

        $this->mock(ShipmentQuoteRequestFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturnNull();
        });
        $this->actingAs($operator)->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(__('transport.manual_quote.details_unavailable_hint'))
            ->assertDontSee(route('transport.shipments.quotes.manual', $shipment));

        $this->mock(ShipmentQuoteRequestFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('build')->andReturnUsing(fn (Shipment $shipment, string $stage): ?array => $stage === 'final' ? $this->request() : null);
        });
        $this->actingAs($operator)->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(route('transport.shipments.quotes.manual', $shipment))
            ->assertSee(__('transport.manual_quote.stage_unavailable_hint', ['stages' => __('transport.quote_stages.final')]))
            ->assertDontSee('value="preliminary"', false);
    }

    public function test_changing_one_stop_number_moves_that_stop_and_duplicates_are_refused_in_chinese(): void
    {
        $coordinator = $this->staff('dispatcher');
        $run = app(DeliveryRunService::class)->create('2026-09-12', $this->staff('transport_operator')->id, 'VAN-B4');
        [$first, $second, $third] = collect(range(1, 3))
            ->map(fn () => app(DeliveryRunService::class)->addShipment($run, $this->shipment('own_fleet'), null))
            ->all();

        // Third row 3 → 1, the other two untouched: the stop moves to the front.
        $this->actingAs($coordinator)
            ->from(route('transport.runs.show', $run))
            ->patch(route('transport.runs.stops.reorder', $run), ['positions' => [
                $first->id => 1, $second->id => 2, $third->id => 1,
            ]])
            ->assertRedirect(route('transport.runs.show', $run))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.runs.order_saved'));
        $this->assertSame([$third->id, $first->id, $second->id], $this->orderedStopIds($run));

        // First row (now the second stop) moved to the end: 2 → 3.
        $this->actingAs($coordinator)
            ->patch(route('transport.runs.stops.reorder', $run), ['positions' => [
                $third->id => 1, $first->id => 3, $second->id => 3,
            ]])
            ->assertSessionHasNoErrors();
        $this->assertSame([$third->id, $second->id, $first->id], $this->orderedStopIds($run));

        // Two stops changed onto the same number: refused in Chinese, typed numbers kept.
        $this->actingAs($coordinator)
            ->from(route('transport.runs.show', $run))
            ->patch(route('transport.runs.stops.reorder', $run), ['positions' => [
                $third->id => 2, $second->id => 2, $first->id => 1,
            ]])
            ->assertRedirect(route('transport.runs.show', $run))
            ->assertSessionHasErrors(['positions' => __('transport.runs.duplicate_positions')])
            ->assertSessionHasInput('positions', [$third->id => '2', $second->id => '2', $first->id => '1']);
        $this->assertSame([$third->id, $second->id, $first->id], $this->orderedStopIds($run));

        $page = $this->actingAs($coordinator)->get(route('transport.runs.show', $run))->assertOk();
        $page->assertSee(__('transport.runs.reorder_hint'));
    }

    public function test_driver_pod_without_a_signature_is_named_in_chinese_and_the_page_carries_the_inline_alert(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $run = app(DeliveryRunService::class)->create('2026-09-10', $driver->id, 'VAN-B4');
        $stop = app(DeliveryRunService::class)->addShipment($run, $this->shipment('own_fleet'), null);

        $this->actingAs($driver)->get(route('transport.driver'))
            ->assertOk()
            ->assertSee('data-signature-error', false)
            ->assertSee(__('transport.driver.signature_required'));

        $this->actingAs($driver)
            ->from(route('transport.driver'))
            ->post(route('transport.driver.stops.deliver', $stop), [
                'recipient_name' => 'Wang',
                'photos' => [UploadedFile::fake()->image('pod.jpg', 100, 80)],
            ])
            ->assertRedirect(route('transport.driver'))
            ->assertSessionHasErrors(['signature_data' => __('transport.driver.signature_required')]);
        $this->assertDatabaseCount('pods', 0);

        $this->actingAs($this->staff('admin'))->get(route('transport.driver'))->assertForbidden();
    }

    /** @return list<int> */
    private function orderedStopIds(DeliveryRun $run): array
    {
        return RunStop::query()->where('delivery_run_id', $run->id)->orderBy('seq')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function shipment(string $source, array $attributes = [], array $quoteAttributes = [], bool $selected = true): Shipment
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B4-'.str()->upper(str()->random(8)),
            'name' => 'Carrier '.$source,
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B4-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'quote_confirmed',
            'carrier_id' => $carrier->id,
            'service_level' => 'standard',
            'tailgate_required' => false,
        ]);
        if (! $selected) {
            return $shipment->refresh();
        }

        $quote = TransportQuote::query()->create($quoteAttributes + [
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => $source,
            'service_level' => 'standard',
            'cost_cents' => 10000,
            'customer_price_cents' => 12000,
            'markup_percent' => 20,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'selected',
            'selected_by' => 'system',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
            'raw_response' => [
                '_quote_request' => [
                    'receiver' => [
                        'name' => 'Receiving Team',
                        'address' => '88 Test Street',
                        'suburb' => 'Sydney',
                        'state' => 'NSW',
                        'postcode' => '2000',
                    ],
                ],
            ],
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
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

    /** Finding (B5): the 毛利 link on every order page 404ed until Transport had a shipment (never, for a 退货 order). */
    public function test_order_margin_page_renders_an_empty_state_for_an_order_without_shipments(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'MARGIN-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]],
        ], null, 'manual');

        $this->actingAs($this->staff('finance'))->get(route('transport.orders.margin', $order->id))
            ->assertOk()
            ->assertSee($order->order_no)
            ->assertSee(__('transport.costs.order_no_shipments'))
            ->assertDontSee(__('transport.costs.formula'));
        $this->actingAs($this->staff('finance'))->get(route('transport.orders.margin', 999999))->assertNotFound();
        $this->actingAs($this->staff('warehouse_operator'))->get(route('transport.orders.margin', $order->id))->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'client_id' => 1,
            'sender' => ['address' => '1 Start St', 'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000', 'type' => 'business'],
            'receiver' => ['address' => '2 End St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
            'items' => [['description' => 'Carton', 'qty' => 1, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
            'declared_value_cents' => 10000,
            'tailgate_pickup' => false,
            'tailgate_delivery' => false,
            'requested_date' => null,
            'zone' => 'metro',
        ];
    }
}
