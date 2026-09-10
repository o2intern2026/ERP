<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Orders\Models\Order;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\ReturnReceipt;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\WarehouseContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B2c / B4 / B13 through the pages: ASN → orders button, outbound board (release → pick → pack → dispatch), return receipt pages. */
class OutboundPagesTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_generate_orders_button_on_the_asn_page(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn] = $this->stockedAsn($client, $warehouse, [['mark' => 'BTN1', 'cartons' => 3], ['mark' => '', 'cartons' => 2]]);
        $cs = $this->staff('customer_service');

        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.generate_orders'));
        $this->actingAs($cs)->post(route('warehouse.asns.generate_orders', $asn))->assertRedirect()->assertSessionHas('status', __('warehouse.asns.orders_generated', ['count' => 1, 'lines' => 1, 'blocked' => 1]));
        $this->assertSame(1, Order::query()->count());
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.blocked_reasons.missing_consignment_mark'));
    }

    public function test_outbound_board_release_pick_pack_dispatch(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'PG1', 'cartons' => 8, 'weight_kg' => 40]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $fulfilment = DB::table('fulfilments')->where('order_id', $order->id)->value('id');

        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee($order->order_no)->assertSee(__('warehouse.outbound.release'));
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$order->id]])->assertRedirect();
        $wave = Wave::query()->firstOrFail();
        $task = WarehouseTask::query()->where('task_type', 'pick')->firstOrFail();
        $line = $task->lines()->firstOrFail();

        $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()->assertSee($task->task_no)->assertSee('MEL-A-01-01');
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $line), ['picked_qty' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('done', $task->fresh()->status);

        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fulfilment))->assertOk()->assertSee(__('warehouse.outbound.packages_hint'));
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [
            ['package_type' => 'carton', 'weight_kg' => '12.5', 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400],
            ['package_type' => '', 'weight_kg' => ''],
        ]])->assertRedirect(route('warehouse.outbound.index'))->assertSessionHasNoErrors();
        $this->assertSame(1, Package::query()->count());
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'outbound.packed', 'job_id' => $asn->job_id]);

        // Tester feedback #9: once packed, the wave page no longer offers 打包 and the pack form explains instead of a bare 409.
        $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()
            ->assertSee(__('warehouse.outbound.packed_badge'))->assertDontSee(route('warehouse.outbound.pack.form', $fulfilment));
        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fulfilment))->assertRedirect(route('warehouse.outbound.index'))
            ->assertSessionHasErrors(['pack' => __('warehouse.outbound.already_packed', ['id' => $fulfilment])]);

        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee('PKG-'.$fulfilment.'-01');
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilment), ['pallet_count' => 0, 'handed_to' => 'carrier'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, OutboundDispatch::query()->count());
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'outbound.dispatched', 'job_id' => $asn->job_id]);
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilment), ['pallet_count' => 0, 'handed_to' => 'carrier'])->assertSessionHasErrors('pallet_count');

        // Read-only roles see the board but not the buttons.
        $this->actingAs($this->staff('finance'))->get(route('warehouse.outbound.index'))->assertOk()->assertDontSee('<button type="submit">'.__('warehouse.outbound.release'), false);
    }

    /** Audit 2026-09-10 blocker: the board eager-loads task.wave; without the inverse relation any un-confirmed pick task 500s the page. */
    public function test_outbound_board_renders_while_a_wave_has_unconfirmed_picks(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'WV1', 'cartons' => 6, 'weight_kg' => 30]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);

        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$order->id]])->assertRedirect();
        $wave = Wave::query()->firstOrFail();
        $task = WarehouseTask::query()->where('task_type', 'pick')->firstOrFail();
        $this->assertSame('pending', $task->status);

        // Board with no warehouse filter and with the wave's warehouse selected: both must render and link the wave.
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee($wave->wave_no)->assertSee(route('warehouse.outbound.waves.show', $wave));
        $this->actingAs($operator)->withSession([WarehouseContext::SESSION_KEY => $warehouse->id])->get(route('warehouse.outbound.index'))->assertOk()->assertSee($wave->wave_no);
        $this->actingAs($this->staff('finance'))->get(route('warehouse.outbound.index'))->assertOk()->assertSee($wave->wave_no);
        $this->assertSame($wave->id, $task->wave->id);
    }

    public function test_return_receipt_pages(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'RP1', 'cartons' => 5]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);

        $this->actingAs($supervisor)->get(route('warehouse.returns.index'))->assertOk()->assertSee(__('warehouse.returns.empty'));
        $this->actingAs($supervisor)->post(route('warehouse.returns.store'), ['order_no' => 'ORD-NOPE', 'warehouse_id' => $warehouse->id])->assertSessionHasErrors('order_no');
        $this->actingAs($supervisor)->post(route('warehouse.returns.store'), ['order_no' => $order->order_no, 'warehouse_id' => $warehouse->id, 'notes' => 'refused at door'])->assertRedirect();
        $receipt = ReturnReceipt::query()->firstOrFail();
        $line = $receipt->lines()->firstOrFail();

        $this->actingAs($supervisor)->get(route('warehouse.returns.show', $receipt))->assertOk()->assertSee($order->order_no)->assertSee(__('warehouse.returns.complete_receiving'));
        $this->actingAs($supervisor)->post(route('warehouse.returns.inspect', [$receipt, $line]), ['disposition' => 'available'])->assertSessionHasErrors('disposition'); // not before receiving
        $this->actingAs($supervisor)->post(route('warehouse.returns.receive', [$receipt, $line]), ['received_qty' => 2, 'condition' => 'good'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_receiving', $receipt))->assertSessionHasNoErrors();
        $this->assertSame('received', $receipt->fresh()->status);
        $this->actingAs($supervisor)->post(route('warehouse.returns.inspect', [$receipt, $line]), ['disposition' => 'available'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_inspection', $receipt))->assertSessionHasNoErrors();
        $this->assertSame('inspected', $receipt->fresh()->status);
        $this->assertNotNull($line->fresh()->stock_unit_id);
        $this->actingAs($supervisor)->get(route('warehouse.returns.show', $receipt))->assertOk()->assertSee(__('warehouse.dispositions.available'))->assertSee($line->fresh()->stockUnit->label_code);
        $this->actingAs($supervisor)->get(route('warehouse.returns.index'))->assertOk()->assertSee($receipt->receipt_no);
    }
}
