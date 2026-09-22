<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #133 (audit TMS-01): a delivery run can be corrected — a pending stop leaves it (the shipment stays booked and is
 * planned again), date / driver / vehicle change while no stop is delivered, a dead run is cancelled with its pending stops
 * released — and the driver finishes a run whose date has passed. The driver still plans nothing (#130).
 */
class RunEditingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-22 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_pending_stop_leaves_the_run_and_its_shipment_is_planned_again(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $driver = $this->staff('transport_operator');
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-22', $driver->id, 'VAN-133');
        $first = $runs->addShipment($run, $this->shipment(), '2026-09-22 10:00:00');
        $second = $runs->addShipment($run, $this->shipment(), '2026-09-22 11:00:00');
        $shipment = $first->shipment;
        $this->assertSame(['booked', $run->id], [$shipment->fresh()->status, $shipment->fresh()->delivery_run_id]);

        // The page offers 移出班次 per pending stop; the button posts the per-stop form rendered after the reorder form.
        $this->actingAs($dispatcher)->get(route('transport.runs.show', $run))->assertOk()
            ->assertSee('action="'.route('transport.runs.stops.destroy', [$run, $first]).'"', false)
            ->assertSee('form="remove-stop-'.$first->id.'"', false)
            ->assertSee(__('transport.runs.remove_stop'));

        $this->actingAs($dispatcher)->delete(route('transport.runs.stops.destroy', [$run, $first]))
            ->assertRedirect(route('transport.runs.show', $run))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.runs.stop_removed'));

        $this->assertDatabaseMissing('run_stops', ['id' => $first->id]);
        $this->assertSame(['booked', null], [$shipment->fresh()->status, $shipment->fresh()->delivery_run_id]);
        $this->assertSame([[$second->id, 1]], $run->fresh()->stops->map(fn (RunStop $s) => [$s->id, $s->seq])->all(), 'the remaining stop is renumbered');
        $this->assertSame('planned', $run->fresh()->status);
        $log = Activity::query()->where('log_name', 'delivery_run')->where('subject_id', $run->id)->latest('id')->firstOrFail();
        $this->assertSame(
            [__('transport.runs.log.stop_removed'), $dispatcher->id, 1, [$shipment->shipment_no]],
            [$log->description, (int) $log->causer_id, $log->properties['attributes']['seq'], $log->properties['attributes']['shipments']],
        );

        // Free again: offered on another run and added to it — still booked, no second booking.
        $other = $runs->create('2026-09-23', $driver->id, 'VAN-133B');
        $this->actingAs($dispatcher)->get(route('transport.runs.show', $other))->assertOk()->assertSee($shipment->shipment_no);
        $this->actingAs($dispatcher)->post(route('transport.runs.stops.store', $other), ['shipment_id' => $shipment->id])->assertSessionHasNoErrors();
        $this->assertSame([$other->id, 'booked'], [$shipment->fresh()->delivery_run_id, $shipment->fresh()->status]);

        // A stop of another run is not this run's: 404, nothing changes.
        $this->actingAs($dispatcher)->delete(route('transport.runs.stops.destroy', [$run, $other->stops()->sole()]))->assertNotFound();
        $this->assertSame([1, 1], [$run->stops()->count(), $other->stops()->count()]);
    }

    public function test_date_driver_and_vehicle_change_while_no_stop_is_delivered_and_the_run_number_stays(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $driver = $this->staff('transport_operator', ['name' => 'Driver Sick']);
        $replacement = $this->staff('transport_operator', ['name' => 'Driver Fresh']);
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-22', $driver->id, 'VAN-OLD');
        $stop = $runs->addShipment($run, $this->shipment());

        $this->actingAs($dispatcher)->get(route('transport.runs.show', $run))->assertOk()
            ->assertSee('action="'.route('transport.runs.update', $run).'"', false)
            ->assertSee('action="'.route('transport.runs.cancel', $run).'"', false)
            ->assertSee(__('transport.runs.edit'))
            ->assertSee('Driver Fresh');

        $this->actingAs($dispatcher)->patch(route('transport.runs.update', $run), ['run_date' => '2026-09-23', 'driver_id' => $replacement->id, 'vehicle' => 'VAN-NEW'])
            ->assertRedirect(route('transport.runs.show', $run))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.runs.updated'));
        $fresh = $run->fresh();
        $this->assertSame(
            ['2026-09-23', $replacement->id, 'VAN-NEW', $run->run_no, 'planned', $run->id],
            [$fresh->run_date->toDateString(), $fresh->driver_id, $fresh->vehicle, $fresh->run_no, $fresh->status, $stop->fresh()->delivery_run_id],
        );
        $log = Activity::query()->where('log_name', 'delivery_run')->where('subject_id', $run->id)->latest('id')->firstOrFail();
        $this->assertSame(
            [__('transport.runs.log.updated'), $dispatcher->id, 'VAN-OLD', '2026-09-22', 'VAN-NEW', '2026-09-23'],
            [$log->description, (int) $log->causer_id, $log->properties['old']['vehicle'], $log->properties['old']['run_date'], $log->properties['attributes']['vehicle'], $log->properties['attributes']['run_date']],
        );

        // The run moved to the new driver: it is on its own-runs list and gone from the old driver's.
        $this->actingAs($replacement)->get(route('transport.runs.index'))->assertOk()->assertSee($run->run_no);
        $this->actingAs($driver)->get(route('transport.runs.index'))->assertOk()->assertDontSee($run->run_no);

        // Only an active transport operator drives; the validator speaks Chinese and keeps the input.
        $this->actingAs($dispatcher)->patch(route('transport.runs.update', $run), ['run_date' => '2026-09-23', 'driver_id' => $dispatcher->id, 'vehicle' => 'VAN-X'])
            ->assertSessionHasErrors(['driver_id' => __('transport.runs.invalid_driver')]);
        $this->actingAs($dispatcher)->patch(route('transport.runs.update', $run), ['run_date' => '23/09/2026', 'driver_id' => $replacement->id, 'vehicle' => ''])
            ->assertSessionHasErrors(['run_date', 'vehicle']);
        $this->assertSame([$replacement->id, 'VAN-NEW'], [$run->fresh()->driver_id, $run->fresh()->vehicle]);
    }

    public function test_cancelling_a_run_releases_its_pending_stops_and_closes_it_to_planning(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $driver = $this->staff('transport_operator');
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-22', $driver->id, 'VAN-CXL');
        $first = $runs->addShipment($run, $this->shipment());
        $second = $runs->addShipment($run, $this->shipment());

        $this->actingAs($dispatcher)->post(route('transport.runs.cancel', $run))
            ->assertRedirect(route('transport.runs.show', $run))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.runs.cancelled'));

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, RunStop::query()->where('delivery_run_id', $run->id)->count());
        foreach ([$first->shipment, $second->shipment] as $shipment) {
            $this->assertSame(['booked', null], [$shipment->fresh()->status, $shipment->fresh()->delivery_run_id]);
        }
        $log = Activity::query()->where('log_name', 'delivery_run')->where('subject_id', $run->id)->latest('id')->firstOrFail();
        $this->assertSame(__('transport.runs.log.cancelled'), $log->description);
        $this->assertEqualsCanonicalizing([$first->shipment->shipment_no, $second->shipment->shipment_no], $log->properties['attributes']['shipments']);

        // Closed: no edit / cancel / add-stop forms; adding, cancelling again and removing are refused; the driver page no longer lists it.
        $this->actingAs($dispatcher)->get(route('transport.runs.show', $run))->assertOk()
            ->assertSee(__('transport.run_statuses.cancelled'))
            ->assertDontSee('action="'.route('transport.runs.update', $run).'"', false)
            ->assertDontSee('action="'.route('transport.runs.cancel', $run).'"', false)
            ->assertDontSee(__('transport.runs.add_shipment'));
        $this->actingAs($dispatcher)->post(route('transport.runs.stops.store', $run), ['shipment_id' => $first->shipment->id])->assertSessionHasErrors('shipment_id');
        $this->actingAs($dispatcher)->post(route('transport.runs.cancel', $run))->assertSessionHasErrors(['run' => __('transport.runs.not_editable')]);
        $this->actingAs($driver)->get(route('transport.driver'))->assertOk()->assertDontSee($run->run_no);
        $this->assertSame(['cancelled', null], [$run->fresh()->status, $first->shipment->fresh()->delivery_run_id]);

        // The released shipments go on a new run for the same day.
        $again = $runs->create('2026-09-22', $driver->id, 'VAN-AGAIN');
        $this->actingAs($dispatcher)->post(route('transport.runs.stops.store', $again), ['shipment_id' => $first->shipment->id])->assertSessionHasNoErrors();
        $this->assertSame($again->id, $first->shipment->fresh()->delivery_run_id);
    }

    public function test_after_a_delivery_the_run_is_neither_edited_nor_cancelled_but_a_pending_stop_still_leaves_it(): void
    {
        Storage::fake('local');
        $dispatcher = $this->staff('dispatcher');
        $driver = $this->staff('transport_operator');
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-22', $driver->id, 'VAN-POD');
        $delivered = $runs->addShipment($run, $this->shipment());
        $pending = $runs->addShipment($run, $this->shipment());
        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $delivered), $this->pod())->assertSessionHasNoErrors();
        $this->assertSame(['delivered', 'dispatched'], [$delivered->fresh()->status, $run->fresh()->status]);

        // No edit / cancel forms, and the routes refuse in Chinese; the delivered stop cannot leave.
        $this->actingAs($dispatcher)->get(route('transport.runs.show', $run))->assertOk()
            ->assertDontSee('action="'.route('transport.runs.update', $run).'"', false)
            ->assertDontSee('action="'.route('transport.runs.cancel', $run).'"', false)
            ->assertDontSee('action="'.route('transport.runs.stops.destroy', [$run, $delivered]).'"', false)
            ->assertSee('action="'.route('transport.runs.stops.destroy', [$run, $pending]).'"', false);
        $this->actingAs($dispatcher)->patch(route('transport.runs.update', $run), ['run_date' => '2026-09-23', 'driver_id' => $driver->id, 'vehicle' => 'VAN-POD'])
            ->assertSessionHasErrors(['run' => __('transport.runs.delivered_stop')]);
        $this->actingAs($dispatcher)->post(route('transport.runs.cancel', $run))
            ->assertSessionHasErrors(['run' => __('transport.runs.delivered_stop')]);
        $this->actingAs($dispatcher)->delete(route('transport.runs.stops.destroy', [$run, $delivered]))
            ->assertSessionHasErrors(['stop' => __('transport.runs.stop_not_pending')]);
        $this->assertSame(['dispatched', 2, '2026-09-22'], [$run->fresh()->status, $run->stops()->count(), $run->fresh()->run_date->toDateString()]);

        // The pending stop still leaves (wrong shipment on the truck, or unreachable today); nothing is open, so the run completes.
        $this->actingAs($dispatcher)->delete(route('transport.runs.stops.destroy', [$run, $pending]))->assertSessionHasNoErrors();
        $this->assertSame(['completed', 1], [$run->fresh()->status, $run->stops()->count()]);
        $this->assertSame(['booked', null], [$pending->shipment->fresh()->status, $pending->shipment->fresh()->delivery_run_id]);

        // Completed: nothing more leaves it, and the page shows no action column.
        $this->actingAs($dispatcher)->delete(route('transport.runs.stops.destroy', [$run, $delivered]))
            ->assertSessionHasErrors(['stop' => __('transport.runs.not_editable')]);
        $this->actingAs($dispatcher)->get(route('transport.runs.show', $run))->assertOk()->assertDontSee(__('transport.runs.remove_stop'));
    }

    public function test_the_driver_finishes_yesterdays_unfinished_run_but_not_tomorrows(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $runs = app(DeliveryRunService::class);

        Carbon::setTestNow('2026-09-21 16:00:00');
        $yesterday = $runs->create('2026-09-21', $driver->id, 'VAN-Y');
        $done = $runs->addShipment($yesterday, $this->shipment());
        $left = $runs->addShipment($yesterday, $this->shipment());
        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $done), $this->pod())->assertSessionHasNoErrors();
        $this->assertSame('dispatched', $yesterday->fresh()->status);

        Carbon::setTestNow('2026-09-22 08:00:00'); // past midnight
        $tomorrow = $runs->create('2026-09-23', $driver->id, 'VAN-T');
        $future = $runs->addShipment($tomorrow, $this->shipment());
        $today = $runs->create('2026-09-22', $driver->id, 'VAN-N');
        $now = $runs->addShipment($today, $this->shipment());

        // Yesterday's unfinished run is listed above today's, flagged with its date; tomorrow's is not there.
        $this->actingAs($driver)->get(route('transport.driver'))->assertOk()
            ->assertSeeInOrder([$yesterday->run_no, $today->run_no])
            ->assertSee(__('transport.driver.run_date', ['date' => '2026-09-21']))
            ->assertSee($left->shipment->shipment_no)
            ->assertSee($now->shipment->shipment_no)
            ->assertDontSee($tomorrow->run_no)
            ->assertDontSee($future->shipment->shipment_no);

        // Tomorrow's stop is not actionable yet.
        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $future), ['failure_reason' => 'other'])
            ->assertSessionHasErrors(['failure_reason' => __('transport.driver.stop_unavailable')]);
        $this->assertSame(['pending', 'planned'], [$future->fresh()->status, $tomorrow->fresh()->status]);

        // Yesterday's last stop is delivered today; the run completes and leaves the page.
        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $left), $this->pod())
            ->assertRedirect(route('transport.driver'))
            ->assertSessionHas('status', __('transport.driver.delivered'));
        $this->assertSame(['delivered', 'completed'], [$left->fresh()->status, $yesterday->fresh()->status]);
        $this->actingAs($driver)->get(route('transport.driver'))->assertOk()
            ->assertDontSee($yesterday->run_no)
            ->assertSee($today->run_no)
            ->assertDontSee(__('transport.driver.run_date', ['date' => '2026-09-21']));
    }

    public function test_the_driver_still_plans_nothing_on_its_own_run(): void
    {
        $driver = $this->staff('transport_operator');
        $runs = app(DeliveryRunService::class);
        $run = $runs->create('2026-09-22', $driver->id, 'VAN-RO');
        $stop = $runs->addShipment($run, $this->shipment());

        $this->actingAs($driver)->get(route('transport.runs.show', $run))->assertOk()
            ->assertDontSee('action="'.route('transport.runs.update', $run).'"', false)
            ->assertDontSee('action="'.route('transport.runs.cancel', $run).'"', false)
            ->assertDontSee('action="'.route('transport.runs.stops.destroy', [$run, $stop]).'"', false)
            ->assertDontSee(__('transport.runs.remove_stop'))
            ->assertDontSee(__('transport.runs.edit'));
        $this->actingAs($driver)->patch(route('transport.runs.update', $run), ['run_date' => '2026-09-23', 'driver_id' => $driver->id, 'vehicle' => 'X'])->assertForbidden();
        $this->actingAs($driver)->post(route('transport.runs.cancel', $run))->assertForbidden();
        $this->actingAs($driver)->delete(route('transport.runs.stops.destroy', [$run, $stop]))->assertForbidden();
        foreach (['finance', 'warehouse_supervisor'] as $role) {
            $this->actingAs($this->staff($role))->post(route('transport.runs.cancel', $run))->assertForbidden();
        }
        $this->assertSame(['planned', 1, $run->id], [$run->fresh()->status, $run->stops()->count(), $stop->shipment->fresh()->delivery_run_id]);
    }

    /** A quote-confirmed own-fleet delivery: adding it to a run books it. */
    private function shipment(): Shipment
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'OWN-133-'.str()->upper(str()->random(8)),
            'name' => 'Own fleet 133',
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-133-'.str()->upper(str()->random(8)),
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
                    'receiver' => ['name' => 'Receiving Team', 'address' => '88 Test Street', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000'],
                ],
            ],
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
    }

    /** @return array<string, mixed> a driver's POD submission */
    private function pod(): array
    {
        return [
            'recipient_name' => 'Receiver 133',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==',
            'photos' => [UploadedFile::fake()->image('pod.jpg', 100, 80)],
        ];
    }
}
