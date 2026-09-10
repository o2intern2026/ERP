<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Exceptions\OrderRuleViolation;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderHoldService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A13 / OMS-11 (ERP_PLAN §3.8 #7): manual financial lock — pick and pack go on, dispatch is refused until Finance / Coordinator release it. */
class FinancialLockTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_locked_order_is_picked_and_packed_but_dispatch_is_refused_until_released_with_a_reason(): void
    {
        $finance = $this->staff('finance', ['name' => 'Fiona Finance']);
        $dispatcher = $this->staff('dispatcher', ['name' => 'Dan Dispatcher']);
        $client = $this->client();
        $order = $this->order($client->id);
        $statuses = app(OrderStatusService::class);

        $this->actingAs($finance)->post(route('orders.holds.store', $order), ['hold_type' => 'financial'])->assertSessionHasErrors('reason');
        $this->actingAs($finance)->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'deposit outstanding'])->assertRedirect();
        $this->assertTrue($order->fresh()->hasActiveFinancialHold());

        foreach (['confirmed', 'allocated', 'picking', 'packed'] as $status) {
            $order = $statuses->transitionOperational($order, $status, $dispatcher->id);
        }
        $this->assertSame('packed', $order->operational_status);

        try {
            $statuses->transitionOperational($order, 'dispatched', $dispatcher->id);
            $this->fail('dispatch must be refused while the financial hold is active');
        } catch (OrderRuleViolation $e) {
            $this->assertSame(__('orders.holds.messages.dispatch_blocked', ['order_no' => $order->order_no]), $e->getMessage());
        }
        $this->assertSame('packed', $order->fresh()->operational_status);

        $holdId = (int) $this->actingAs($finance)->get(route('orders.show', $order))->assertSee('deposit outstanding')->assertSee(__('orders.holds.release'))->viewData('holds')->first()->id;
        $this->actingAs($dispatcher)->post(route('orders.holds.release', [$order, $holdId]), [])->assertSessionHasErrors('note');
        $this->actingAs($dispatcher)->post(route('orders.holds.release', [$order, $holdId]), ['note' => 'deposit received'])->assertRedirect();
        $this->assertFalse($order->fresh()->hasActiveFinancialHold());

        $order = $statuses->transitionOperational($order, 'dispatched', $dispatcher->id);
        $this->assertSame('dispatched', $order->operational_status);

        $this->actingAs($finance)->get(route('orders.show', $order))->assertOk()
            ->assertSee(route('transport.orders.margin', $order->id)) // X2 handoff: staff-only link to "收 − 付 = 毛利" (never rendered in the portal, see PortalOrdersTest)
            ->assertSee(__('orders.holds.timeline.placed', ['type' => __('orders.holds.types.financial'), 'reason' => 'deposit outstanding']))
            ->assertSee(__('orders.holds.timeline.released', ['type' => __('orders.holds.types.financial'), 'note' => 'deposit received']))
            ->assertSeeInOrder(['Fiona Finance', 'Dan Dispatcher']);
    }

    public function test_warehouse_dispatch_is_refused_under_a_lock_and_flows_through_after_finance_releases(): void
    {
        $finance = $this->staff('finance');
        $operator = $this->staff('warehouse_operator');
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'LOCK', 'cartons' => 5]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 5]]);
        $fulfilment = $order->fulfilments()->sole();
        $this->actingAs($finance)->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'overdue account'])->assertRedirect();

        $outbound = app(OutboundService::class);
        $task = $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id)['tasks']->sole();
        foreach ($task->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $operator->id);
        }
        $outbound->pack($fulfilment->id, [['package_type' => 'carton', 'weight_kg' => 5]], $operator->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('packed', $order->fresh()->operational_status, 'picking and packing are not blocked by a financial hold');

        // CHANGE_REQUESTS #40 (delivered in PR #10): the Warehouse handover itself is refused while the hold is active.
        try {
            $outbound->dispatch($fulfilment->id, 0, 'carrier', null, $operator->id);
            $this->fail('Warehouse must refuse the handover while a financial hold is active');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('financial hold', $e->getMessage());
        }
        $this->assertSame('packed', $fulfilment->fresh()->status);
        $this->assertSame('packed', $order->fresh()->operational_status);

        $holdId = (int) app(OrderHoldService::class)->activeFor($order)->first()->id;
        $this->actingAs($finance)->post(route('orders.holds.release', [$order, $holdId]), ['note' => 'paid'])->assertRedirect();
        $outbound->dispatch($fulfilment->id, 0, 'carrier', null, $operator->id);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('dispatched', $fulfilment->fresh()->status);
        $this->assertSame('dispatched', $order->fresh()->operational_status);
    }

    public function test_only_finance_or_admin_lock_and_the_list_highlights_locked_orders(): void
    {
        $client = $this->client();
        $order = $this->order($client->id);
        $other = $this->order($client->id);
        $this->actingAs($this->staff('customer_service'))->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->staff('dispatcher'))->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($this->staff('admin'))->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'credit limit'])->assertRedirect();

        $this->assertTrue($order->fresh()->hasActiveFinancialHold());
        $this->assertFalse($other->fresh()->hasActiveFinancialHold());
        $this->actingAs($this->staff('customer_service'))->get('/orders')->assertOk()->assertSee(__('orders.holds.financial_badge'));
    }

    /** 2026-09-10 i18n sweep: a hold released by someone else in the meantime is refused in Chinese, never with the old English service text. */
    public function test_releasing_a_hold_that_is_no_longer_active_is_refused_in_chinese(): void
    {
        $finance = $this->staff('finance');
        $client = $this->client();
        $order = $this->order($client->id);
        $holds = app(OrderHoldService::class);
        $holdId = $holds->place($order, 'financial', 'deposit outstanding', $finance->id);
        $holds->release($order, $holdId, 'paid', $finance->id);

        try {
            $holds->release($order, $holdId, 'paid again', $finance->id);
            $this->fail('a released hold must not be released twice');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(__('orders.holds.messages.not_active'), $e->getMessage());
            $this->assertStringNotContainsString('No active hold', $e->getMessage());
        }

        // Over HTTP the controller answers a stale id with the (Chinese) 404 page before the service is reached.
        $this->actingAs($finance)->post(route('orders.holds.release', [$order, $holdId]), ['note' => 'paid again'])->assertNotFound();
    }

    private function order(int $clientId): Order
    {
        $job = app(JobService::class)->create($clientId, 'loose')['job_id'];

        return app(OrderCreationService::class)->create([
            'client_id' => $clientId, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'FL-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]],
        ], null, 'manual');
    }
}
