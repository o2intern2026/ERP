<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\TransportOptionService;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use App\Support\Fakes\FakeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** M4 handoff: real packages/outbound events drive the M5 Manual quote-to-dispatch path (§5.7 #1/#2). */
class M4TransportIntegrationTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_real_packed_packages_drive_final_manual_quote_booking_cost_and_dispatch(): void
    {
        $transportOperator = $this->staff('transport_operator');
        $warehouseOperator = $this->staff('warehouse_operator');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $warehouse->update([
            'address' => '10 Warehouse Road',
        ]);
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [[
            'mark' => 'M4-TMS',
            'cartons' => 2,
            'weight_kg' => 20,
        ]]);

        $ownCarrier = Carrier::query()->create(['code' => 'OWN-M4-TMS', 'name' => 'Own fleet M4', 'status' => 'active']);
        CarrierService::query()->create([
            'carrier_id' => $ownCarrier->id,
            'source' => 'own_fleet',
            'service_level' => 'standard',
            'default_eta_days' => 1,
            'active' => true,
        ]);
        $manualCarrier = Carrier::query()->create(['code' => 'MAN-M4-TMS', 'name' => 'Manual carrier M4', 'status' => 'active']);
        $manualService = CarrierService::query()->create([
            'carrier_id' => $manualCarrier->id,
            'source' => 'manual',
            'service_level' => 'standard',
            'default_eta_days' => 2,
            'active' => true,
        ]);

        $rates = app(RateService::class);
        $this->assertInstanceOf(FakeRateService::class, $rates);
        $rates->withRate('TR-DELIVERY-BASE', 10000);
        $this->app->instance(TransportOptionServiceContract::class, app(TransportOptionService::class));

        $order = $this->confirmedOrder($client, $asn->job_id, [[
            'asn_line_id' => $asnLines[0]->id,
            'qty' => 2,
            'weight_kg' => 20,
        ]]);
        $shipment = Shipment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('quoting', $shipment->status);

        $fulfilment = DB::table('fulfilments')->where('order_id', $order->id)->firstOrFail();
        $outbound = app(OutboundService::class);
        $task = $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $warehouseOperator->id)['tasks']->sole();
        foreach ($task->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $warehouseOperator->id);
        }
        $outbound->pack($fulfilment->id, [[
            'package_type' => 'carton',
            'weight_kg' => 20,
            'length_mm' => 600,
            'width_mm' => 400,
            'height_mm' => 300,
        ]], $warehouseOperator->id);
        app(OutboxDispatcher::class)->dispatchDue();

        $shipment->refresh();
        $this->assertSame($fulfilment->id, $shipment->fulfilment_id);
        $this->assertSame('quoted', $shipment->status);
        $automatic = TransportQuote::query()->where('shipment_id', $shipment->id)->where('source', 'own_fleet')->sole();
        $this->assertSame('final', $automatic->quote_stage);
        $this->assertSame(600, data_get($automatic->raw_response, '_quote_request.items.0.length_mm'));
        $this->assertSame('PKG-'.$fulfilment->id.'-01', data_get($automatic->raw_response, '_quote_request.items.0.description'));

        $this->actingAs($transportOperator)->post(route('transport.shipments.quotes.manual', $shipment), [
            'carrier_service_id' => $manualService->id,
            'quote_stage' => 'final',
            'cost_cents' => 7000,
            'customer_price_cents' => 9000,
            'eta_days' => 1,
        ])->assertSessionHasNoErrors();
        $manual = TransportQuote::query()->where('shipment_id', $shipment->id)->where('source', 'manual')->sole();
        $this->assertTrue($manual->is_recommended);
        $this->assertTrue($manual->is_cheapest);
        $this->assertSame(20.0, (float) data_get($manual->raw_response, '_quote_request.items.0.weight_kg'));

        app(QuoteSelectionService::class)->select($shipment->refresh(), $manual, 'coordinator', $transportOperator->id);
        $this->actingAs($transportOperator)->post(route('transport.shipments.book', $shipment), [
            'booking_reference' => 'MANUAL-M4-100',
            'tracking_number' => 'TRACK-M4-100',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('carrier_costs', [
            'shipment_id' => $shipment->id,
            'expected_cost_cents' => 7000,
            'actual_cost_cents' => null,
        ]);

        $outbound->dispatch($fulfilment->id, 0, 'carrier', $shipment->id, $warehouseOperator->id);
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame('dispatched', $shipment->fresh()->status);
        $this->assertNotNull($shipment->fresh()->dispatched_at);
        $this->assertSame(1, Shipment::query()->where('order_id', $order->id)->count());
    }
}
