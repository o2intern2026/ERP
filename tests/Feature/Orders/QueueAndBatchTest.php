<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderHoldService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A15 Coordinator queue (OMS-2), holds (§3.3), A14 batch view (§3.8 #9). Covered by C for X1 — HANDOFF.md. */
class QueueAndBatchTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function order(int $clientId, int $jobId, array $overrides = []): Order
    {
        return app(OrderCreationService::class)->createManual(array_replace_recursive([
            'client_id' => $clientId, 'job_id' => $jobId, 'order_type' => 'from_stock', 'external_ref' => 'Q-'.uniqid(),
            'deliver_to_name' => 'R', 'deliver_to_address' => '1 St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 5, 'asn_line_id' => 1]],
        ], $overrides), null);
    }

    public function test_queue_presets_show_the_right_orders(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $dispatcher = $this->staff('dispatcher');
        $finance = $this->staff('finance');

        $pending = $this->order($client->id, $job);
        $short = $this->order($client->id, $job);
        app(OrderStatusService::class)->transitionOperational($short, 'confirmed');
        $short->lines()->update(['qty_backordered' => 2]);
        $today = $this->order($client->id, $job, ['requested_date' => today()->toDateString()]);
        app(OrderStatusService::class)->transitionOperational($today, 'confirmed');
        $held = $this->order($client->id, $job);
        app(OrderHoldService::class)->place($held, 'financial', 'overdue account', $finance->id);
        $flagged = $this->order($client->id, $job);
        app(ExceptionService::class)->raise('pick_short', 'warehouse', ['client_id' => $client->id, 'order_id' => $flagged->id, 'message' => 'short 2']);

        $see = fn (string $view, array $yes, array $no) => tap($this->actingAs($dispatcher)->get('/orders/queue?view='.$view)->assertOk(), function ($r) use ($yes, $no) {
            foreach ($yes as $o) {
                $r->assertSee($o->order_no);
            }
            foreach ($no as $o) {
                $r->assertDontSee($o->order_no);
            }
        });

        $see('pending_confirm', [$pending, $held, $flagged], [$short, $today]);
        $see('short', [$short], [$pending, $today, $held]);
        $see('today', [$today], [$pending, $short]);
        $see('financial_hold', [$held], [$pending, $short, $today, $flagged]);
        $see('exceptions', [$flagged], [$pending, $held]);
        $this->actingAs($dispatcher)->get('/orders/queue')->assertOk()->assertSee(__('orders.queue.views.financial_hold'));
        $this->actingAs($this->staff('warehouse_operator'))->get('/orders/queue')->assertForbidden();
    }

    public function test_holds_are_placed_and_released_from_the_order_page_with_finance_gate(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $order = $this->order($client->id, $job);
        $dispatcher = $this->staff('dispatcher');
        $finance = $this->staff('finance');

        $this->actingAs($dispatcher)->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($dispatcher)->post(route('orders.holds.store', $order), ['hold_type' => 'address', 'reason' => 'postcode does not match suburb'])->assertRedirect();
        $this->actingAs($finance)->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'account overdue'])->assertRedirect();

        $holds = app(OrderHoldService::class)->activeFor($order);
        $this->assertSame(['address', 'financial'], $holds->pluck('hold_type')->sort()->values()->all());
        $this->assertTrue(app(ExceptionService::class)->hasActiveHold('financial', $client->id, $order->id));
        $this->assertDatabaseHas('exceptions', ['type' => 'hold', 'source_module' => 'orders', 'order_id' => $order->id, 'hold_type' => 'financial', 'message' => 'account overdue']);
        $this->actingAs($dispatcher)->get(route('orders.show', $order))->assertOk()->assertSee('account overdue')->assertSee(__('orders.holds.types.address'));

        $financial = $holds->firstWhere('hold_type', 'financial');
        // A13: a financial hold is released by Finance / admin / the dispatcher (Coordinator) — never by customer service.
        $this->actingAs($this->staff('customer_service'))->post(route('orders.holds.release', [$order, $financial->id]), ['note' => 'nope'])->assertForbidden();
        $this->actingAs($finance)->post(route('orders.holds.release', [$order, $financial->id]), ['note' => 'paid today'])->assertRedirect();
        $this->assertFalse(app(ExceptionService::class)->hasActiveHold('financial', $client->id, $order->id));
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'note' => __('orders.holds.timeline.released', ['type' => __('orders.holds.types.financial'), 'note' => 'paid today'])]);
        $this->assertDatabaseHas('exceptions', ['order_id' => $order->id, 'hold_type' => 'financial', 'status' => 'resolved', 'release_reason' => 'paid today']);
    }

    public function test_batch_page_lists_every_order_generated_from_one_container(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'COSU6508115030', 'size' => '40', 'unpack_mode' => 'loose']]]);
        [$lineA, $lineB] = app(AsnService::class)->addLines($asn, [
            ['container_no' => 'COSU6508115030', 'consignment_mark' => 'GD20260506BC', 'description' => 'motor', 'expected_cartons' => 10],
            ['container_no' => 'COSU6508115030', 'consignment_mark' => 'JJ26051603', 'description' => 'lighting', 'expected_cartons' => 4],
        ]);
        $other = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$lineC] = app(AsnService::class)->addLines($other, [['description' => 'unrelated', 'expected_cartons' => 1]]);

        $orderA = $this->order($client->id, $asn->job_id, ['consignment_mark' => 'GD20260506BC', 'lines' => [['carton_qty' => 10, 'asn_line_id' => $lineA->id]]]);
        $orderB = $this->order($client->id, $asn->job_id, ['consignment_mark' => 'JJ26051603', 'lines' => [['carton_qty' => 4, 'asn_line_id' => $lineB->id]]]);
        $orderC = $this->order($client->id, $other->job_id, ['lines' => [['carton_qty' => 1, 'asn_line_id' => $lineC->id]]]);
        $orderA->lines()->update(['qty_shipped' => 10]);
        $cs = $this->staff('customer_service');

        $byContainer = $this->actingAs($cs)->get('/orders/batches?ref=COSU6508115030')->assertOk();
        $byContainer->assertSee($asn->asn_no)->assertSee($orderA->order_no)->assertSee($orderB->order_no)->assertDontSee($orderC->order_no)->assertSee(__('orders.batches.revenue_pending'));
        $this->actingAs($cs)->get('/orders/batches?ref='.$asn->asn_no)->assertOk()->assertSee($orderA->order_no)->assertSee('14'); // 14 cartons ordered
        $this->actingAs($cs)->get('/orders/batches?ref=NOPE')->assertOk()->assertSee(__('orders.batches.not_found', ['ref' => 'NOPE']));
        $this->actingAs($cs)->get(route('orders.show', $orderA))->assertOk()->assertSee($asn->asn_no)->assertSee('COSU6508115030'); // ASN chip on the line
    }
}
