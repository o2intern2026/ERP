<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\ReturnService;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A11 / OMS-4 + OMS-9 (ERP_PLAN §3.4, §3.8 #4): change / cancel by stage, order.cancelled / order.reduced, and the return chain. */
class OrderChangeAndReturnTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_before_picking_a_coordinator_cancels_freely_and_warehouse_releases_the_reservation(): void
    {
        [$order, $asnLine, $client] = $this->allocatedOrder(6);
        $cs = $this->staff('customer_service');
        $this->assertSame(6, app(StockService::class)->onHand($client->id, $asnLine->id)['qty_reserved']);

        $this->actingAs($cs)->post(route('orders.cancel', $order), [])->assertSessionHasErrors('reason');
        $this->actingAs($cs)->post(route('orders.cancel', $order), ['reason' => 'client withdrew the PO'])->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame('cancelled', $order->operational_status);
        $event = OutboxEvent::query()->where('event_name', 'order.cancelled')->sole();
        $this->assertEquals([
            'order_id' => $order->id, 'order_no' => $order->order_no, 'job_id' => $order->job_id, 'client_id' => $order->client_id,
            'previous_status' => 'allocated', 'cancelled_by' => $cs->id, 'reason' => 'client withdrew the PO',
        ], collect($event->payload)->except('cancelled_at')->all());
        $this->assertArrayHasKey('cancelled_at', $event->payload);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'to_status' => 'cancelled', 'actor_id' => $cs->id, 'note' => __('orders.changes.timeline.cancelled', ['reason' => 'client withdrew the PO'])]);

        app(OutboxDispatcher::class)->dispatchDue(); // Warehouse consumes order.cancelled → reservations released
        $this->assertSame(0, app(StockService::class)->onHand($client->id, $asnLine->id)['qty_reserved']);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'stock.released', 'job_id' => $order->job_id]);
    }

    public function test_from_picking_on_only_a_supervisor_or_admin_may_cancel_or_change_and_a_reason_is_required(): void
    {
        [$order] = $this->allocatedOrder(6);
        app(OrderStatusService::class)->transitionOperational($order, 'picking');
        $cs = $this->staff('customer_service');
        $supervisor = $this->staff('warehouse_supervisor');

        $this->actingAs($cs)->post(route('orders.cancel', $order), ['reason' => 'x'])->assertSessionHasErrors('change');
        $this->assertSame('picking', $order->fresh()->operational_status);
        $this->actingAs($cs)->patch(route('orders.update', $order), $this->delivery())->assertForbidden();
        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.changes.hint_supervised'))->assertDontSee(__('orders.changes.cancel_title'));

        $this->actingAs($supervisor)->patch(route('orders.update', $order), $this->delivery())->assertSessionHasErrors('reason');
        $this->actingAs($supervisor)->patch(route('orders.update', $order), $this->delivery() + ['reason' => 'consignee moved'])->assertRedirect();
        $this->assertSame('9 New Street', $order->fresh()->deliver_to_address);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $supervisor->id, 'note' => __('orders.changes.timeline.delivery_updated', ['reason' => 'consignee moved'])]);

        $this->actingAs($supervisor)->post(route('orders.cancel', $order), ['reason' => 'damaged during pick'])->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->operational_status);
        $this->assertSame('picking', OutboxEvent::query()->where('event_name', 'order.cancelled')->sole()->payload['previous_status']);
    }

    public function test_quantity_reduction_shrinks_the_batch_and_emits_order_reduced_for_reserved_lines(): void
    {
        [$order, $asnLine, $client] = $this->allocatedOrder(6);
        $line = $order->lines()->sole();
        $dispatcher = $this->staff('dispatcher');

        $this->actingAs($dispatcher)->post(route('orders.reduce', $order), ['quantities' => [$line->id => 8], 'reason' => 'more'])->assertSessionHasErrors('change');
        $this->actingAs($dispatcher)->post(route('orders.reduce', $order), ['quantities' => [$line->id => 0], 'reason' => 'none'])->assertSessionHasErrors('change');
        $this->actingAs($dispatcher)->post(route('orders.reduce', $order), ['quantities' => [$line->id => 4], 'reason' => 'client cut the PO'])->assertRedirect();

        $order->refresh()->load('lines', 'fulfilments.lines');
        $this->assertSame(4, $order->lines->first()->carton_qty);
        $this->assertSame(4, $order->fulfilments->sole()->lines->sole()->qty);
        $this->assertSame(0, $order->lines->first()->qty_backordered);
        $this->assertSame('allocated', $order->operational_status);

        $event = OutboxEvent::query()->where('event_name', 'order.reduced')->sole();
        $this->assertEquals([
            'order_id' => $order->id, 'order_no' => $order->order_no, 'job_id' => $order->job_id, 'client_id' => $order->client_id,
            'lines' => [['order_line_id' => $line->id, 'asn_line_id' => $asnLine->id, 'old_qty' => 6, 'new_qty' => 4]],
            'changed_by' => $dispatcher->id, 'reason' => 'client cut the PO',
        ], collect($event->payload)->except('changed_at')->all());

        app(OutboxDispatcher::class)->dispatchDue(); // Warehouse releases and re-reserves the new quantity
        $this->assertSame(4, app(StockService::class)->onHand($client->id, $asnLine->id)['qty_reserved']);
    }

    public function test_shipped_orders_cannot_be_edited_cancelled_or_reduced_only_returned(): void
    {
        [$order] = $this->allocatedOrder(6);
        $statuses = app(OrderStatusService::class);
        foreach (['picking', 'packed', 'dispatched'] as $status) {
            $statuses->transitionOperational($order, $status);
        }
        $admin = $this->staff('admin');

        $this->actingAs($admin)->patch(route('orders.update', $order), $this->delivery() + ['reason' => 'x'])->assertForbidden();
        $this->actingAs($admin)->post(route('orders.cancel', $order), ['reason' => 'x'])->assertSessionHasErrors('change');
        $this->actingAs($admin)->post(route('orders.reduce', $order), ['quantities' => [$order->lines()->sole()->id => 1], 'reason' => 'x'])->assertSessionHasErrors('change');
        $this->assertSame('dispatched', $order->fresh()->operational_status);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'order.cancelled']);
        $this->actingAs($admin)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.changes.hint_shipped'))->assertSee(__('orders.returns.request_title'));
    }

    public function test_return_chain_from_request_through_inspection_to_the_financial_decision(): void
    {
        [$order, $asnLine, $client, $warehouse] = $this->allocatedOrder(6);
        $statuses = app(OrderStatusService::class);
        foreach (['picking', 'packed', 'dispatched', 'delivered'] as $status) {
            $statuses->transitionOperational($order, $status);
        }
        $order->fulfilments()->sole()->update(['status' => 'delivered', 'shipment_id' => 4242]);
        $line = $order->lines()->sole();
        $cs = $this->staff('customer_service');
        $finance = $this->staff('finance', ['name' => 'Fiona Finance']);

        // A return on an unshipped order is refused; on the delivered one it creates the linked return order.
        $this->actingAs($cs)->post(route('orders.returns.store', $this->allocatedOrder(2)[0]), ['quantities' => [1], 'reason' => 'x'])->assertSessionHasErrors('return');
        $this->actingAs($cs)->post(route('orders.returns.store', $order), ['quantities' => [$line->id => 7], 'reason' => 'too many'])->assertSessionHasErrors('return');
        $response = $this->actingAs($cs)->post(route('orders.returns.store', $order), ['quantities' => [$line->id => 2], 'reason' => 'consignee refused two cartons']);

        $return = Order::query()->where('order_type', 'return')->sole();
        $response->assertRedirect(route('orders.show', $return));
        $this->assertSame([$order->id, 'confirmed', $order->job_id, 'manual'], [$return->original_order_id, $return->operational_status, $return->job_id, $return->source]);
        $this->assertSame([$line->id, $asnLine->id, 2], [$return->lines->sole()->original_order_line_id, $return->lines->sole()->asn_line_id, $return->lines->sole()->carton_qty]);
        $this->assertNotContains($return->id, OutboxEvent::query()->where('event_name', 'order.confirmed')->get()->map(fn ($e) => $e->payload['order_id'])->all(), 'confirming a return order must not ask Warehouse to reserve stock');

        $requested = OutboxEvent::query()->where('event_name', 'return.requested')->sole();
        $this->assertEquals([
            'return_order_id' => $return->id, 'return_order_no' => $return->order_no, 'original_order_id' => $order->id, 'original_shipment_id' => 4242,
            'job_id' => $order->job_id, 'client_id' => $client->id, 'reason' => 'consignee refused two cartons',
            'lines' => [['original_order_line_id' => $line->id, 'asn_line_id' => $asnLine->id, 'qty' => 2]],
            'pickup_address' => ['name' => 'Receiver Pty Ltd', 'phone' => null, 'address' => '1 Test St', 'suburb' => 'Melbourne', 'state' => 'VIC', 'postcode' => '3000'],
            'requested_by' => $cs->id,
        ], collect($requested->payload)->except('requested_at')->all());
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $cs->id, 'note' => __('orders.returns.timeline.raised', ['return_no' => $return->order_no, 'reason' => 'consignee refused two cartons'])]);

        // Finance cannot decide before Warehouse inspected the goods.
        $this->actingAs($finance)->post(route('orders.returns.decide', $return), ['decision' => 'credit', 'note' => 'early'])->assertSessionHasErrors('return');
        $this->actingAs($finance)->get(route('orders.show', $return))->assertOk()->assertSee(__('orders.returns.inspection_pending'))->assertSee($order->order_no);

        // Warehouse (B13) receives and inspects → return.inspected → the return order is `returned`, the original keeps its history.
        $warehouseSvc = app(ReturnService::class);
        $receipt = $warehouseSvc->open(['original_order_id' => $order->id, 'return_order_id' => $return->id, 'warehouse_id' => $warehouse->id]);
        $receiptLine = $receipt->lines->sole();
        $warehouseSvc->receiveLine($receiptLine, 2, 'good');
        $warehouseSvc->completeReceiving($receipt->fresh());
        $warehouseSvc->inspectLine($receiptLine->fresh(), 'available', $this->staff('warehouse_supervisor')->id);
        $warehouseSvc->completeInspection($receipt->fresh());
        app(OutboxDispatcher::class)->dispatchDue();

        $return->refresh();
        $this->assertSame('returned', $return->operational_status);
        $this->assertNotNull($return->return_inspected_at);
        $this->assertSame('delivered', $order->fresh()->operational_status);
        $inspectedNote = __('orders.returns.timeline.inspected', ['receipt' => $receipt->id, 'summary' => __('orders.returns.dispositions.available').' × 2']);
        $this->assertDatabaseHas('order_events', ['order_id' => $return->id, 'to_status' => 'returned', 'actor_type' => 'system', 'note' => $inspectedNote]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'to_status' => 'delivered', 'actor_type' => 'system', 'note' => $inspectedNote]);

        // Finance records the decision once; it is the only credit trigger (CHANGE_REQUESTS #11).
        $this->actingAs($cs)->post(route('orders.returns.decide', $return), ['decision' => 'credit', 'note' => 'x'])->assertForbidden();
        $this->actingAs($finance)->get(route('orders.show', $return))->assertOk()->assertSee(__('orders.returns.record_decision'));
        $this->actingAs($finance)->post(route('orders.returns.decide', $return), ['decision' => 'credit', 'note' => 'refund two cartons'])->assertRedirect();
        $this->actingAs($finance)->post(route('orders.returns.decide', $return), ['decision' => 'no_credit', 'note' => 'again'])->assertSessionHasErrors('return');

        $return->refresh();
        $this->assertSame(['credit', $finance->id, 'refund two cartons'], [$return->return_decision, $return->return_decided_by, $return->return_decision_note]);
        $decision = OutboxEvent::query()->where('event_name', 'return.financial_decision')->sole();
        $this->assertEquals([
            'return_order_id' => $return->id, 'original_order_id' => $order->id, 'job_id' => $order->job_id, 'client_id' => $client->id, 'decision' => 'credit',
            'credit_lines' => [['original_charge_id' => null, 'original_order_line_id' => $line->id, 'qty' => 2, 'amount_cents' => null, 'reason' => 'refund two cartons']],
            'decided_by' => $finance->id, 'note' => 'refund two cartons',
        ], collect($decision->payload)->except('decided_at')->all());
        $this->actingAs($finance)->get(route('orders.show', $return))->assertOk()->assertSee(__('orders.returns.decisions.credit'))->assertSee('Fiona Finance');
        $this->actingAs($finance)->get(route('orders.show', $order))->assertOk()->assertSee($return->order_no);
    }

    /** @return array{Order, AsnLine, Client, Warehouse} */
    private function allocatedOrder(int $qty): array
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'CHG', 'cartons' => 10]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => $qty]]);
        $this->assertSame('allocated', $order->operational_status);

        return [$order, $asnLines[0], $client, $warehouse];
    }

    /** @return array<string, string> */
    private function delivery(): array
    {
        return ['deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '9 New Street', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'requested_date' => today()->addDays(2)->toDateString()];
    }
}
