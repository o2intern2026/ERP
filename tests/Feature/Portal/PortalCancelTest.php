<?php

namespace Tests\Feature\Portal;

use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CR #111 (2026-09-10): the client cancels directly before picking, files a request during picking / packing, and only returns after shipping. */
class PortalCancelTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_stage_one_the_client_cancels_directly_and_the_reservation_is_released(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'CXL1', 'cartons' => 8]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $this->assertSame('allocated', $order->operational_status);
        $this->assertSame(3, app(StockService::class)->onHand($client->id, $asnLines[0]->id)['qty_reserved']);

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee(__('portal.cancel.cancel'))->assertSee(route('portal.orders.cancel', $order))->assertDontSee(route('portal.orders.cancel_request', $order));

        $this->actingAs($user)->post(route('portal.orders.cancel', $order), [])->assertSessionHasErrors('reason');
        $this->actingAs($user)->post(route('portal.orders.cancel', $order), ['reason' => '客户改期,先不出货'])->assertRedirect(route('portal.orders.show', $order));

        $this->assertSame('cancelled', $order->fresh()->operational_status);
        $event = OutboxEvent::query()->where('event_name', 'order.cancelled')->where('payload->order_id', $order->id)->firstOrFail();
        $this->assertSame(['客户改期,先不出货', $user->id], [$event->payload['reason'], $event->payload['cancelled_by']]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'to_status' => 'cancelled']);
        $this->assertStringContainsString('客户取消', $order->events()->where('to_status', 'cancelled')->value('note') ?? '');

        app(OutboxDispatcher::class)->dispatchDue(); // Warehouse releases the reservation
        $this->assertSame(0, app(StockService::class)->onHand($client->id, $asnLines[0]->id)['qty_reserved']);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('portal.cancel.already_cancelled'));

        // Another client's order is invisible to this client.
        $this->actingAs($this->clientUser())->post(route('portal.orders.cancel', $order), ['reason' => 'x'])->assertNotFound();
    }

    public function test_stage_two_the_client_files_a_request_that_the_coordinator_rejects_or_executes(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'CXL2', 'cartons' => 8]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        app(OrderStatusService::class)->transitionOperational($order, 'picking');

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee(__('portal.cancel.request'))->assertSee(route('portal.orders.cancel_request', $order))->assertDontSee('action="'.route('portal.orders.cancel', $order).'"', false); // the cancel URL is a prefix of the cancel-request URL

        $this->actingAs($user)->post(route('portal.orders.cancel', $order), ['reason' => '不要了'])->assertSessionHasErrors(['cancel' => __('orders.changes.messages.client_stage_locked')]);
        $this->assertSame('picking', $order->fresh()->operational_status);

        $this->actingAs($user)->post(route('portal.orders.cancel_request', $order), ['reason' => '客户端下错单'])->assertRedirect(route('portal.orders.show', $order));
        $request = ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'cancel_request')->where('order_id', $order->id)->sole();
        $this->assertSame('open', $request->status);
        $this->assertStringContainsString('客户端下错单', $request->message);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('portal.cancel.pending_badge'))->assertDontSee(route('portal.orders.cancel_request', $order));
        $this->actingAs($user)->post(route('portal.orders.cancel_request', $order), ['reason' => '再来一次'])->assertSessionHasErrors(['cancel' => __('orders.changes.messages.cancel_request_open')]);

        // Customer service sees the banner; picking-stage cancel is a supervisor call, so only 拒绝 is offered to CS.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.cancel_request.banner'))->assertSee(route('orders.cancel_request.reject', $order))->assertSee(__('orders.changes.messages.supervisor_required'));
        $this->actingAs($cs)->post(route('orders.cancel_request.reject', $order), ['reason' => '已经装车'])->assertRedirect(route('orders.show', $order));
        $this->assertSame('resolved', $request->fresh()->status);
        $this->assertStringContainsString('已经装车', $request->fresh()->message);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('portal.cancel.rejected_badge'))->assertSee('已经装车')->assertSee(route('portal.orders.cancel_request', $order));

        // A second request, then a supervisor executes the cancel from the banner → the request closes with the order.
        $this->actingAs($user)->post(route('portal.orders.cancel_request', $order), ['reason' => '客户坚持取消'])->assertRedirect();
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor)->get(route('orders.show', $order))->assertOk()->assertSee(route('orders.cancel', $order));
        $this->actingAs($supervisor)->post(route('orders.cancel', $order), ['reason' => '按客户申请取消'])->assertRedirect(route('orders.show', $order));
        $this->assertSame('cancelled', $order->fresh()->operational_status);
        $this->assertSame(0, ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'cancel_request')->where('order_id', $order->id)->where('status', 'open')->count());
    }

    public function test_stage_three_only_the_return_request_remains(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'CXL3', 'cartons' => 8]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $statuses = app(OrderStatusService::class);
        $statuses->transitionOperational($order, 'picking');
        $statuses->transitionOperational($order->fresh(), 'packed');
        $statuses->transitionOperational($order->fresh(), 'dispatched');

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee(__('portal.cancel.shipped_note'))->assertSee('#return-request')->assertDontSee(route('portal.orders.cancel', $order))->assertDontSee(route('portal.orders.cancel_request', $order));
        $this->actingAs($user)->post(route('portal.orders.cancel', $order), ['reason' => 'x'])->assertSessionHasErrors(['cancel' => __('orders.changes.messages.shipped_locked')]);
        $this->actingAs($user)->post(route('portal.orders.cancel_request', $order), ['reason' => 'x'])->assertSessionHasErrors(['cancel' => __('orders.changes.messages.shipped_locked')]);
        $this->assertSame('dispatched', $order->fresh()->operational_status);
    }
}
