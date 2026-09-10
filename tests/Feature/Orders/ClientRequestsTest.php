<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CR #112: staff see every client cancel / return request in one inbox, with a pending count on the menu. */
class ClientRequestsTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_staff_see_pending_client_requests_act_on_them_and_the_menu_counts_them(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'REQ1', 'cartons' => 20]]);
        $statuses = app(OrderStatusService::class);

        // A picking-stage order → the client files a cancel request.
        $picking = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $statuses->transitionOperational($picking, 'picking');
        $this->actingAs($user)->post(route('portal.orders.cancel_request', $picking), ['reason' => '客户改主意了'])->assertRedirect();

        // A shipped order → the client files a return request (a portal return order).
        $shipped = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        foreach (['picking', 'packed', 'dispatched'] as $status) {
            $statuses->transitionOperational($shipped->fresh(), $status);
        }
        $this->actingAs($user)->post(route('portal.orders.returns.store', $shipped), ['quantities' => [$shipped->lines()->first()->id => 1], 'reason' => '外箱破损'])->assertRedirect();
        $return = Order::query()->withoutGlobalScopes()->where('order_type', 'return')->sole();

        // Customer service: both requests listed, the menu badge says 2, cancel can only be rejected at this stage.
        $cs = $this->staff('customer_service');
        $page = $this->actingAs($cs)->get(route('orders.requests.index'))->assertOk();
        $page->assertSee($picking->order_no)->assertSee('客户改主意了')->assertSee($return->order_no)->assertSee($shipped->order_no)->assertSee('外箱破损')
            ->assertSee(__('orders.requests.needs_supervisor'))->assertSee(route('orders.cancel_request.reject', $picking))
            ->assertDontSee('action="'.route('orders.cancel', $picking).'"', false)
            ->assertSee('<span class="badge" data-tone="warn">2</span>', false);

        // A warehouse supervisor may execute the cancel straight from the inbox.
        $this->actingAs($this->staff('warehouse_supervisor'))->get(route('orders.requests.index'))->assertOk()->assertSee('action="'.route('orders.cancel', $picking).'"', false);

        // CS rejects → it leaves the pending list and shows in the history as 已拒绝.
        $this->actingAs($cs)->post(route('orders.cancel_request.reject', $picking), ['reason' => '已在装车'])->assertRedirect();
        $after = $this->actingAs($cs)->get(route('orders.requests.index'))->assertOk();
        $after->assertSee(__('orders.requests.cancels_empty'))->assertSee(__('orders.requests.outcome_rejected'))->assertSee('已在装车')
            ->assertSee('<span class="badge" data-tone="warn">1</span>', false); // the return request is still pending

        // Roles: clients never reach it, and a driver has no business here either.
        $this->actingAs($user)->get(route('orders.requests.index'))->assertForbidden();
        $this->actingAs($this->staff('transport_operator'))->get(route('orders.requests.index'))->assertForbidden();
    }
}
