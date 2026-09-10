<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\StockUnit;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** Item 4 B/C/D (2026-09-10): a confirmed order that cannot be reserved is visible (board block, order banner, 缺货 exception) and heals itself on putaway. */
class StockShortageTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_a_short_reservation_is_shown_on_the_board_and_the_order_and_resolves_after_putaway(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'SHORT1', 'cartons' => 4]]);
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock', 'external_ref' => 'SHORT-1',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 10, 'asn_line_id' => $asnLines[0]->id]],
        ], null, 'manual');

        app(OrderStatusService::class)->transitionOperational($order, 'confirmed'); // immediate dispatch reserves 4 of 10

        $line = $order->lines()->first();
        $this->assertSame(6, (int) $line->fresh()->qty_backordered);
        $exception = ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'stock_shortage')->where('order_id', $order->id)->sole();
        $this->assertSame('open', $exception->status);
        $this->assertStringContainsString('缺 6 箱', $exception->message);

        // Board: the shortage block names the order and the line; the release form carries the client / date filters (D).
        $board = $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk();
        $board->assertSee(__('warehouse.outbound.shortage_title'))->assertSee($order->order_no)->assertSee('缺 6 箱')->assertSee($asn->asn_no)
            ->assertSee('name="client_id"', false)->assertSee('name="requested_date"', false);

        // Order page banner.
        $this->actingAs($this->staff('customer_service'))->get(route('orders.show', $order))->assertOk()
            ->assertSee(__('orders.fulfilments.shortage_banner'))->assertSee('缺 6 箱');

        // B: refusals are Chinese — releasing with a client filter that matches nothing.
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'client_id' => 999999])
            ->assertSessionHasErrors(['warehouse_id' => __('warehouse.outbound.errors.no_candidates')]);

        // More stock for the same ASN line arrives and is put away → the backorder is allocated and the exception closes itself.
        StockUnit::query()->create([
            'client_id' => $client->id, 'job_id' => $asn->job_id, 'asn_line_id' => $asnLines[0]->id, 'warehouse_id' => $warehouse->id, 'unit_type' => 'carton',
            'label_code' => 'SHORT1-TOPUP', 'location_id' => $this->location($warehouse, 'storage')->id, 'qty_on_hand' => 10, 'qty_reserved' => 0,
            'condition' => 'good', 'putaway_completed' => true, 'received_at' => now(),
        ]);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent(['asn_id' => $asn->id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'lines' => [['asn_line_id' => $asnLines[0]->id]]], 'asn.putaway_completed', jobId: $asn->job_id, clientId: $client->id)));
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(0, (int) $line->fresh()->qty_backordered);
        $this->assertSame('resolved', $exception->fresh()->status);
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertDontSee(__('warehouse.outbound.shortage_title'));
    }
}
