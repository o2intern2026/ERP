<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B4 end to end with the real OMS: wave → pick (location order, pick short) → pack (outbound.packed) → dispatch (§4.7 #4 #5 #10 #11 #22 #23). */
class OutboundFlowTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_wave_picks_packs_and_dispatches_a_confirmed_order(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [
            ['mark' => 'M1', 'cartons' => 20, 'weight_kg' => 200, 'location_type' => 'pickface'],   // 10 kg cartons in the pickface
            ['mark' => 'M1', 'cartons' => 5, 'weight_kg' => 250, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 5, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1200, 'weight_kg' => 250, 'pallet_source' => 'client_own']]],
        ]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 6], ['asn_line_id' => $asnLines[1]->id, 'qty' => 5]]);
        $fulfilment = DB::table('fulfilments')->where('order_id', $order->id)->first();
        $this->assertNotNull($fulfilment, 'OMS should have built a fulfilment from stock.reserved');
        $this->assertSame('allocated', $fulfilment->status);

        $service = app(OutboundService::class);
        ['wave' => $wave, 'tasks' => $tasks] = $service->releaseWave($warehouse->id, ['client_id' => $client->id], $operator->id);
        $this->assertMatchesRegularExpression('/^WAV-\d{8}-0001$/', $wave->wave_no);
        $this->assertCount(1, $tasks);
        $task = $tasks->first();
        $this->assertSame(['MEL-A-01-01', 'MEL-PF-01-01'], $task->lines->map(fn ($l) => $l->location->full_code)->all()); // sorted by location path (§4.7 #4)
        $this->assertSame([5, 6], $task->lines->pluck('required_qty')->all());
        try {
            $service->releaseWave($warehouse->id, ['client_id' => $client->id]);
            $this->fail('a second wave must not pick the same fulfilment twice');
        } catch (InvalidArgumentException) {
        }

        // Pick: pallet in full, cartons 4 of 6 → Pick Short exception, shortfall released.
        [$palletLine, $cartonLine] = $task->lines;
        $service->confirmPick($palletLine, 5, $operator->id);
        $service->confirmPick($cartonLine, 4, $operator->id);
        $this->assertDatabaseHas('exceptions', ['type' => 'pick_short', 'source_type' => 'fulfilment', 'source_id' => $fulfilment->id, 'order_id' => $order->id]); // §4.7 #10
        $this->assertSame('done', $task->fresh()->status);
        $this->assertSame('completed', $wave->fresh()->status);
        $this->assertSame(['qty_on_hand' => 16, 'qty_reserved' => 0, 'qty_available' => 16], app(StockService::class)->onHand($client->id, $asnLines[0]->id)); // 20 − 4 picked, shortfall released
        $this->assertSame(['qty_on_hand' => 0, 'qty_reserved' => 0, 'qty_available' => 0], app(StockService::class)->onHand($client->id, $asnLines[1]->id));
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $cartonLine->stock_unit_id, 'movement_type' => 'pick', 'qty' => -4]);
        $this->artisan('stock:reconcile')->assertSuccessful();

        // Pack: two packages, a 25 kg one among them → dimensions and weights travel to TMS / Billing (§4.7 #5 #22).
        $result = $service->pack($fulfilment->id, [['package_type' => 'pallet', 'weight_kg' => 250, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1200], ['package_type' => 'carton', 'weight_kg' => 25, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400]], $operator->id);
        $this->assertCount(2, $result['packages']);
        $packed = OutboxEvent::query()->where('event_name', 'outbound.packed')->firstOrFail();
        $p = $packed->payload;
        $this->assertSame($fulfilment->id, $p['fulfilment_id']);
        $this->assertSame(1, $p['pallet_count']);
        $this->assertSame(4, $p['carton_count']);
        $this->assertSame(2, $p['label_count']);
        $this->assertFalse($p['is_urgent']);
        $this->assertEquals([['unit_type' => 'pallet', 'qty' => 5, 'unit_weight_kg' => 250.0], ['unit_type' => 'carton', 'qty' => 4, 'unit_weight_kg' => 10.0]], array_map(fn ($l) => ['unit_type' => $l['unit_type'], 'qty' => $l['qty'], 'unit_weight_kg' => $l['unit_weight_kg']], $p['lines']));
        $this->assertSame('PKG-'.$fulfilment->id.'-02', $p['packages'][1]['carton_label']);
        $this->assertEqualsWithDelta(25.0, $p['packages'][1]['weight_kg'], 0.001);
        $this->assertDatabaseHas('warehouse_tasks', ['task_type' => 'pack', 'fulfilment_id' => $fulfilment->id, 'status' => 'done', 'billable_qty' => 2, 'billable_uom' => 'label']);

        // Dispatch: packed and dispatched are two timestamps; loaded pallets become a load task (§4.7 #11 #23).
        try {
            $service->pack($fulfilment->id, [['package_type' => 'carton', 'weight_kg' => 1]]);
            $this->fail('packing twice must be refused');
        } catch (InvalidArgumentException) {
        }
        $dispatch = $service->dispatch($fulfilment->id, 1, 'carrier', null, $operator->id);
        $this->assertInstanceOf(OutboundDispatch::class, $dispatch);
        $this->assertTrue($dispatch->dispatched_at->greaterThanOrEqualTo($packed->created_at));
        $this->assertDatabaseHas('warehouse_tasks', ['task_type' => 'load', 'fulfilment_id' => $fulfilment->id, 'status' => 'done', 'billable_qty' => 1, 'billable_uom' => 'pallet']);
        $dispatched = OutboxEvent::query()->where('event_name', 'outbound.dispatched')->firstOrFail();
        $this->assertSame(['carrier', 1, 2], [$dispatched->payload['handed_to'], $dispatched->payload['pallet_count'], $dispatched->payload['package_count']]);
        $this->assertSame(3, WarehouseTask::query()->where('fulfilment_id', $fulfilment->id)->count()); // pick, pack, load
    }
}
