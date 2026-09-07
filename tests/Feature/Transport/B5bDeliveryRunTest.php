<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B5bDeliveryRunTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_delivery_run_tables_and_enums_match_the_transport_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('delivery_runs', [
            'run_no', 'run_date', 'driver_id', 'vehicle', 'status',
        ]));
        $this->assertTrue(Schema::hasColumns('run_stops', [
            'delivery_run_id', 'shipment_id', 'seq', 'eta', 'arrived_at', 'status',
        ]));
        $this->assertSame(['planned', 'dispatched', 'completed', 'cancelled'], TransportEnums::DELIVERY_RUN_STATUSES);
        $this->assertSame(['pending', 'arrived', 'delivered', 'failed'], TransportEnums::RUN_STOP_STATUSES);

        $this->expectException(InvalidArgumentException::class);
        DeliveryRun::query()->create([
            'run_no' => 'RUN-INVALID',
            'run_date' => '2026-09-08',
            'driver_id' => $this->staff('transport_operator')->id,
            'vehicle' => 'TRUCK-01',
            'status' => 'loading',
        ]);
    }

    public function test_coordinator_creates_a_run_with_an_active_transport_driver_and_vehicle(): void
    {
        $coordinator = $this->staff('dispatcher');
        $driver = $this->staff('transport_operator', ['name' => 'Driver B5b']);

        $response = $this->actingAs($coordinator)->post(route('transport.runs.store'), [
            'run_date' => '2026-09-09',
            'driver_id' => $driver->id,
            'vehicle' => 'VAN-B5B',
        ]);

        $run = DeliveryRun::query()->sole();
        $response->assertRedirect(route('transport.runs.show', $run))
            ->assertSessionHas('status', __('transport.runs.created'));
        $this->assertSame('planned', $run->status);
        $this->assertSame($driver->id, $run->driver_id);
        $this->assertSame('VAN-B5B', $run->vehicle);
        $this->assertStringStartsWith('RUN-20260909-', $run->run_no);

        $this->actingAs($coordinator)
            ->get(route('transport.runs.index'))
            ->assertOk()
            ->assertSee($run->run_no)
            ->assertSee('Driver B5b')
            ->assertSee('VAN-B5B');

        $inactiveDriver = $this->staff('transport_operator', ['is_active' => false]);
        $this->actingAs($coordinator)->post(route('transport.runs.store'), [
            'run_date' => '2026-09-10',
            'driver_id' => $inactiveDriver->id,
            'vehicle' => 'VAN-INACTIVE',
        ])->assertSessionHasErrors('driver_id');
        $this->assertSame(1, DeliveryRun::query()->count());

        $this->actingAs($this->staff('finance'))
            ->get(route('transport.runs.create'))
            ->assertForbidden();
    }

    public function test_only_confirmed_own_fleet_shipments_are_added_as_ordered_stops(): void
    {
        $coordinator = $this->staff('transport_operator');
        $run = $this->deliveryRun($coordinator->id);
        $first = $this->shipment('own_fleet');
        $second = $this->shipment('own_fleet', ['status' => 'booked']);
        $thirdParty = $this->shipment('transdirect');

        $this->actingAs($coordinator)
            ->post(route('transport.runs.stops.store', $run), [
                'shipment_id' => $first->id,
                'eta' => '2026-09-09 10:30:00',
            ])->assertSessionHasNoErrors();
        $this->actingAs($coordinator)
            ->post(route('transport.runs.stops.store', $run), ['shipment_id' => $second->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('run_stops', [
            'delivery_run_id' => $run->id,
            'shipment_id' => $first->id,
            'seq' => 1,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('run_stops', [
            'delivery_run_id' => $run->id,
            'shipment_id' => $second->id,
            'seq' => 2,
            'status' => 'pending',
        ]);
        $this->assertSame($run->id, $first->fresh()->delivery_run_id);
        $this->assertSame($run->id, $second->fresh()->delivery_run_id);

        $this->actingAs($coordinator)
            ->post(route('transport.runs.stops.store', $run), ['shipment_id' => $thirdParty->id])
            ->assertSessionHasErrors('shipment_id');
        $this->assertNull($thirdParty->fresh()->delivery_run_id);
        $this->assertDatabaseMissing('run_stops', ['shipment_id' => $thirdParty->id]);
    }

    public function test_coordinator_can_reorder_every_stop_before_dispatch(): void
    {
        $coordinator = $this->staff('transport_operator');
        $run = $this->deliveryRun($coordinator->id);
        $first = $this->shipment('own_fleet');
        $second = $this->shipment('own_fleet');
        $third = $this->shipment('own_fleet');
        $service = app(DeliveryRunService::class);
        $firstStop = $service->addShipment($run, $first);
        $secondStop = $service->addShipment($run, $second);
        $thirdStop = $service->addShipment($run, $third);

        $this->actingAs($coordinator)->patch(route('transport.runs.stops.reorder', $run), [
            'positions' => [
                $firstStop->id => 3,
                $secondStop->id => 1,
                $thirdStop->id => 2,
            ],
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.runs.order_saved'));

        $this->assertDatabaseHas('run_stops', ['id' => $secondStop->id, 'seq' => 1]);
        $this->assertDatabaseHas('run_stops', ['id' => $thirdStop->id, 'seq' => 2]);
        $this->assertDatabaseHas('run_stops', ['id' => $firstStop->id, 'seq' => 3]);
        $this->actingAs($coordinator)
            ->get(route('transport.runs.show', $run))
            ->assertOk()
            ->assertSeeInOrder([$second->shipment_no, $third->shipment_no, $first->shipment_no]);

        $this->actingAs($coordinator)->patch(route('transport.runs.stops.reorder', $run), [
            'positions' => [$firstStop->id => 2, $secondStop->id => 1],
        ])->assertSessionHasErrors('positions');
        $this->assertSame([1, 2, 3], $run->fresh()->stops->pluck('seq')->all());

        $run->update(['status' => 'dispatched']);
        $this->actingAs($coordinator)->patch(route('transport.runs.stops.reorder', $run), [
            'positions' => [
                $firstStop->id => 1,
                $secondStop->id => 2,
                $thirdStop->id => 3,
            ],
        ])->assertSessionHasErrors('positions');
    }

    private function deliveryRun(int $driverId): DeliveryRun
    {
        return app(DeliveryRunService::class)->create('2026-09-09', $driverId, 'TRUCK-B5B');
    }

    private function shipment(string $source, array $attributes = []): Shipment
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B5B-'.str()->upper(str()->random(8)),
            'name' => 'Carrier '.$source,
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B5B-'.str()->upper(str()->random(8)),
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
            'cost_cents' => 10000,
            'customer_price_cents' => 12000,
            'markup_percent' => 20,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'selected',
            'selected_by' => 'system',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
    }
}
