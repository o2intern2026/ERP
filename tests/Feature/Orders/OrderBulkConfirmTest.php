<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #153 一键确认: the orders list confirms every ticked 已接收 order in one post with the single button's checks; refused ones are named. */
class OrderBulkConfirmTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function receivedOrder(int $clientId, int $jobId, array $line, string $type = 'from_stock'): Order
    {
        return app(OrderCreationService::class)->create([
            'client_id' => $clientId, 'job_id' => $jobId, 'order_type' => $type, 'external_ref' => 'BC-'.uniqid(),
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'pickup_address' => $type === 'pickup_deliver' ? ['name' => 'Shipper', 'phone' => '', 'address' => '5 Depot Rd', 'suburb' => 'Alexandria', 'state' => 'NSW', 'postcode' => '2015'] : null,
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 2, 'actual_weight_kg' => 10] + $line],
            'declared_packages' => $type === 'pickup_deliver' ? [['package_type' => 'carton', 'qty' => 2, 'weight_kg' => 5]] : [],
        ], null, 'manual');
    }

    public function test_ticked_received_orders_are_confirmed_in_one_post_and_an_unlinked_stock_order_is_named(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'BC1', 'cartons' => 8, 'weight_kg' => 40]]);
        $cs = $this->staff('customer_service');
        $linkedA = $this->receivedOrder($client->id, $asn->job_id, ['asn_line_id' => $asnLines[0]->id]);
        $linkedB = $this->receivedOrder($client->id, $asn->job_id, ['asn_line_id' => $asnLines[0]->id]);
        $unlinked = $this->receivedOrder($client->id, $asn->job_id, []);
        $pickup = $this->receivedOrder($client->id, $asn->job_id, [], 'pickup_deliver');
        $already = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 1]]);

        // The list: a checkbox per 已接收 order bound to the bulk form, none for a confirmed one; no raw lang key.
        $page = $this->actingAs($cs)->get(route('orders.index'))->assertOk()
            ->assertSee('id="confirm-bulk"', false)->assertSee('name="order_ids[]" value="'.$linkedA->id.'" form="confirm-bulk"', false)
            ->assertDontSee('name="order_ids[]" value="'.$already->id.'"', false)
            ->assertSee(__('orders.bulk_confirm.title'))->assertSee(__('orders.bulk_confirm.select_all', ['count' => 4]));
        $this->assertDoesNotMatchRegularExpression('/orders\.bulk_confirm\./', $page->getContent());
        // The page size selector (25 / 100 / 300) decides how many orders 全选本页 covers; an unknown size is refused.
        $this->actingAs($cs)->get(route('orders.index', ['per_page' => 300, 'status' => 'received']))->assertOk()->assertSee('name="per_page"', false)->assertSee(__('orders.filters.per_page_option', ['count' => 300]));
        $this->actingAs($cs)->get(route('orders.index', ['per_page' => 999]))->assertSessionHasErrors('per_page');

        // Nothing ticked → Chinese refusal. All four ticked → three confirmed, the unlinked from_stock order named with the single button's reason.
        $this->actingAs($cs)->post(route('orders.confirm_bulk'), [])->assertSessionHasErrors('order_ids');
        $this->actingAs($cs)->from(route('orders.index'))->post(route('orders.confirm_bulk'), ['order_ids' => [$linkedA->id, $linkedB->id, $unlinked->id, $pickup->id, $already->id]])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('status', __('orders.bulk_confirm.done', ['count' => 3]))
            ->assertSessionHasErrors('order_ids');
        $errors = session('errors')->first('order_ids');
        $this->assertStringContainsString($unlinked->order_no, $errors);
        $this->assertStringContainsString($already->order_no, $errors, 'an already confirmed order is refused like the single button would');
        $this->assertStringContainsString('2 张未确认', $errors);
        // order.confirmed is dispatched at once (config erp.outbox_dispatch_now): the stocked orders may already be allocated by the time we look.
        $this->assertContains($linkedA->fresh()->operational_status, ['confirmed', 'allocated']);
        $this->assertContains($linkedB->fresh()->operational_status, ['confirmed', 'allocated']);
        $this->assertSame(['received', 'confirmed'], [$unlinked->fresh()->operational_status, $pickup->fresh()->operational_status]);

        // Roles: a warehouse operator and a client user are refused; the operator's list has no bulk form.
        $this->actingAs($this->staff('warehouse_operator'))->post(route('orders.confirm_bulk'), ['order_ids' => [$unlinked->id]])->assertForbidden();
        $this->actingAs($this->staff('warehouse_operator'))->get(route('orders.index'))->assertOk()->assertDontSee('id="confirm-bulk"', false);
        $this->actingAs($this->clientUser($client))->post(route('orders.confirm_bulk'), ['order_ids' => [$unlinked->id]])->assertForbidden();
    }
}
