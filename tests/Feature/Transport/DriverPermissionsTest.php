<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Models\CarrierInvoice;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #130 (lead 2026-09-17): the dispatcher plans, the driver executes. A transport_operator keeps the driver page
 * and a read-only view of its own runs; every planning, shipment, cost and reconciliation route is refused (403) or, for
 * another driver's run, not revealed (404). Planners, finance and the board readers are unchanged.
 */
class DriverPermissionsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-17 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_driver_is_refused_every_planning_shipment_cost_and_reconciliation_route(): void
    {
        $driver = $this->staff('transport_operator');
        $shipment = $this->shipment();
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-17', $driver->id, 'VAN-130');
        $stop = $runs->addShipment($run, $this->shipment(), null);
        $invoice = CarrierInvoice::query()->create([
            'carrier_id' => $shipment->carrier_id,
            'invoice_no' => 'INV-130',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-15',
            'total_cents' => 1000,
            'status' => 'received',
        ]);

        foreach ([
            route('transport.index'),
            route('transport.shipments.show', $shipment),
            route('transport.shipments.consignment-note', $shipment),
            route('transport.shipments.label', $shipment),
            route('transport.runs.create'),
            route('transport.carrier-invoices.index'),
            route('transport.carrier-invoices.show', $invoice),
            route('transport.carrier-invoices.differences', $invoice),
            route('transport.orders.margin', $shipment->order_id),
        ] as $url) {
            $this->actingAs($driver)->get($url)->assertForbidden();
        }

        foreach ([
            ['post', route('transport.runs.store'), ['run_date' => '2026-09-17', 'driver_id' => $driver->id, 'vehicle' => 'VAN-X']],
            ['post', route('transport.runs.stops.store', $run), ['shipment_id' => $shipment->id]], // even on the driver's own run
            ['patch', route('transport.runs.stops.reorder', $run), ['positions' => [$stop->id => 1]]],
            ['post', route('transport.carrier-invoices.store'), []],
            ['post', route('transport.shipments.pod.store', $shipment), []],
            ['post', route('transport.shipments.extra-charges.store', $shipment), []],
            ['post', route('transport.shipments.book', $shipment), []],
            ['post', route('transport.shipments.quotes.manual', $shipment), []],
            ['post', route('transport.shipments.own-fleet-cost.store', $shipment), []],
            ['post', route('transport.shipments.redelivery.store', $shipment), []],
            ['post', route('transport.shipments.quotes.select', [$shipment, $shipment->selectedQuote]), []],
        ] as [$method, $url, $data]) {
            $this->actingAs($driver)->{$method}($url, $data)->assertForbidden();
        }

        $this->assertSame(1, DeliveryRun::query()->count());
        $this->assertNull($shipment->fresh()->delivery_run_id);
        $this->assertSame(1, $run->stops()->count());
    }

    public function test_driver_sees_only_own_runs_read_only_and_another_drivers_run_is_not_revealed(): void
    {
        $driver = $this->staff('transport_operator', ['name' => 'Driver Own']);
        $other = $this->staff('transport_operator', ['name' => 'Driver Other']);
        $runs = app(DeliveryRunService::class);
        $own = $runs->create('2026-09-17', $driver->id, 'VAN-OWN');
        $foreign = $runs->create('2026-09-17', $other->id, 'VAN-OTHER');
        $stop = $runs->addShipment($own, $this->shipment(), '2026-09-17 10:30:00');

        $this->actingAs($driver)->get(route('transport.runs.index'))
            ->assertOk()
            ->assertSee($own->run_no)
            ->assertDontSee($foreign->run_no)
            ->assertDontSee(route('transport.runs.create'));

        // The run page is a read-only list: no add-stop form, no sequence inputs, no save button, shipment numbers as text.
        $this->actingAs($driver)->get(route('transport.runs.show', $own))
            ->assertOk()
            ->assertSee($own->run_no)
            ->assertSee($stop->shipment->shipment_no)
            ->assertSee('2026-09-17 10:30')
            ->assertDontSee(__('transport.runs.add_shipment'))
            ->assertDontSee(route('transport.runs.stops.store', $own))
            ->assertDontSee(route('transport.runs.stops.reorder', $own))
            ->assertDontSee(__('transport.runs.reorder_hint'))
            ->assertDontSee(__('transport.runs.save_order'))
            ->assertDontSee('name="positions[', false)
            ->assertDontSee('href="'.route('transport.shipments.show', $stop->shipment).'"', false);

        $this->actingAs($driver)->get(route('transport.runs.show', $foreign))->assertNotFound();

        // A dispatcher who also drives is still a planner: every run, every form.
        $both = $this->staff('dispatcher');
        $both->assignRole('transport_operator');
        $this->actingAs($both)->get(route('transport.runs.index'))
            ->assertOk()
            ->assertSee($own->run_no)
            ->assertSee($foreign->run_no)
            ->assertSee(route('transport.runs.create'));
        $this->actingAs($both)->get(route('transport.runs.show', $own))
            ->assertOk()
            ->assertSee(route('transport.runs.stops.reorder', $own))
            ->assertSee('name="positions[', false);
    }

    public function test_driver_page_and_its_deliver_and_fail_actions_still_work_end_to_end(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-17', $driver->id, 'VAN-E2E');
        $first = $runs->addShipment($run, $this->shipment(), '2026-09-17 10:00:00');
        $second = $runs->addShipment($run, $this->shipment(), '2026-09-17 11:00:00');

        $this->actingAs($driver)->get(route('transport.driver'))
            ->assertOk()
            ->assertSee($first->shipment->shipment_no)
            ->assertSee($second->shipment->shipment_no)
            ->assertSee(route('transport.driver.stops.deliver', $first))
            ->assertSee(route('transport.driver.stops.fail', $second))
            // Nav: 班次 (own runs) and 司机任务 stay; the 运输 board and 承运商对账 are gone.
            ->assertSee(route('transport.runs.index'))
            ->assertSee(route('transport.driver'))
            ->assertDontSee('href="'.route('transport.index').'"', false)
            ->assertDontSee(route('transport.carrier-invoices.index'));

        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $first), [
            'recipient_name' => 'Receiver 130',
            'signature_data' => $this->signatureData(),
            'photos' => [UploadedFile::fake()->image('pod.jpg', 100, 80)],
        ])->assertRedirect(route('transport.driver'))
            ->assertSessionHas('status', __('transport.driver.delivered'));
        $this->assertSame('delivered', $first->fresh()->status);
        $this->assertSame('delivered', $first->shipment->fresh()->status);
        $this->assertSame(1, Pod::query()->whereNotNull('delivered_at')->count());

        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $second), ['failure_reason' => 'recipient_unavailable'])
            ->assertRedirect(route('transport.driver'))
            ->assertSessionHas('status', __('transport.driver.failed'));
        $this->assertSame('failed', $second->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_planners_finance_and_the_board_readers_are_unchanged(): void
    {
        $driver = $this->staff('transport_operator', ['name' => 'Driver Plan']);
        $shipment = $this->shipment();
        $dispatcher = $this->staff('dispatcher');

        // The dispatcher creates the run, adds the stop, reorders, and opens the run list / run page / board / shipment page / PDF.
        $this->actingAs($dispatcher)->post(route('transport.runs.store'), ['run_date' => '2026-09-17', 'driver_id' => $driver->id, 'vehicle' => 'VAN-D'])
            ->assertSessionHasNoErrors();
        $run = DeliveryRun::query()->sole();
        $this->actingAs($dispatcher)->post(route('transport.runs.stops.store', $run), ['shipment_id' => $shipment->id])->assertSessionHasNoErrors();
        $stop = $run->stops()->sole();
        $this->actingAs($dispatcher)->patch(route('transport.runs.stops.reorder', $run), ['positions' => [$stop->id => 1]])->assertSessionHasNoErrors();
        $this->actingAs($dispatcher)->get(route('transport.runs.index'))->assertOk()->assertSee(route('transport.runs.create'))->assertSee($run->run_no);
        $this->actingAs($dispatcher)->get(route('transport.runs.show', $run))
            ->assertOk()
            ->assertSee(route('transport.runs.stops.reorder', $run))
            ->assertSee('href="'.route('transport.shipments.show', $shipment).'"', false);
        $this->actingAs($dispatcher)->get(route('transport.index'))
            ->assertOk()
            ->assertSee($shipment->shipment_no)
            ->assertSee('href="'.route('transport.index').'"', false)
            ->assertSee(route('transport.runs.index'))
            ->assertDontSee(route('transport.carrier-invoices.index'))
            ->assertDontSee(route('transport.driver'));
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(route('transport.shipments.extra-charges.store', $shipment))
            ->assertSee(route('transport.shipments.own-fleet-cost.store', $shipment));
        $this->actingAs($dispatcher)->get(route('transport.shipments.consignment-note', $shipment))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Admin and finance keep 承运商对账 and the margin page; finance still reads the shipment page (own-fleet cost form included).
        $this->actingAs($this->staff('admin'))->get(route('transport.carrier-invoices.index'))->assertOk();
        $finance = $this->staff('finance');
        $this->actingAs($finance)->get(route('transport.carrier-invoices.index'))->assertOk()->assertDontSee(route('transport.runs.index'));
        $this->actingAs($finance)->get(route('transport.orders.margin', $shipment->order_id))->assertOk();
        $this->actingAs($finance)->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(route('transport.shipments.own-fleet-cost.store', $shipment))
            ->assertDontSee(route('transport.shipments.extra-charges.store', $shipment));

        // Customer service and the warehouse roles keep their read access to the board and the shipment page.
        $this->actingAs($this->staff('customer_service'))->get(route('transport.index'))->assertOk();
        $this->actingAs($this->staff('warehouse_supervisor'))->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertDontSee(route('transport.shipments.extra-charges.store', $shipment));
        $this->actingAs($this->staff('warehouse_operator'))->get(route('transport.index'))->assertOk();

        // The label stays with the planners only.
        $this->actingAs($finance)->get(route('transport.shipments.label', $shipment))->assertForbidden();
        $this->actingAs($driver)->get(route('transport.shipments.label', $shipment))->assertForbidden();
    }

    private function shipment(): Shipment
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'OWN-130-'.str()->upper(str()->random(8)),
            'name' => 'Own fleet 130',
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-130-'.str()->upper(str()->random(8)),
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
            'source' => 'own_fleet',
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

    private function signatureData(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';
    }
}
