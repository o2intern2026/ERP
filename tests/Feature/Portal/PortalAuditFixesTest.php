<?php

namespace Tests\Feature\Portal;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Portal\Http\PortalValidation;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * 2026-09-10 audit, lane B package B3 (Portal): the client-facing forms must never offer an action the server refuses,
 * never lose what the client typed, and never print English validation with dotted array keys.
 */
class PortalAuditFixesTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @return array<string, mixed> */
    private function fromStockPayload(array $overrides = []): array
    {
        return array_replace([
            'order_type' => 'from_stock',
            'deliver_to_name' => 'Amazon BWU2', 'deliver_to_address' => '1 Distribution Drive', 'deliver_to_suburb' => 'Kemps Creek',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2178', 'deliver_to_address_type' => 'fba',
            'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '纸箱货', 'package_type' => 'carton', 'carton_qty' => 5]],
        ], $overrides);
    }

    public function test_a_stale_hidden_pickup_package_row_never_blocks_a_from_stock_estimate(): void
    {
        $user = $this->clientUser($this->client());

        // The 提货 fieldset is disabled (not only hidden) client side, so its controls are never serialised…
        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()->assertSee('pickup.disabled = !pure', false);

        // …and a leftover row that still reaches the server (老 tab, scripted post) is dropped for a non-transport order.
        $this->actingAs($user)->post(route('portal.orders.preview'), $this->fromStockPayload([
            'declared_packages' => [['package_type' => 'carton', 'weight_kg' => 25]],
        ]))->assertOk()->assertSee(__('portal.estimate.preview_title'))->assertSessionDoesntHaveErrors();

        $this->actingAs($user)->post(route('portal.orders.store'), $this->fromStockPayload([
            'external_ref' => 'STALE-PKG-1', 'declared_packages' => [['package_type' => 'carton', 'weight_kg' => 25]],
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('external_ref', 'STALE-PKG-1')->sole()->declaredPackages()->count());
    }

    public function test_return_request_is_not_offered_on_a_line_less_pure_transport_order(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client, [
            'order_type' => 'pickup_deliver', 'lines' => [],
            'pickup_address' => ['name' => 'Factory', 'phone' => null, 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028'],
            'declared_packages' => [['package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 100]],
        ]);
        foreach (['confirmed', 'dispatched'] as $status) {
            app(OrderStatusService::class)->transitionOperational($order, $status);
        }
        $this->assertTrue($order->fresh()->acceptsReturnRequest());

        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()
            ->assertDontSee('<summary>'.__('portal.returns.request').'</summary>', false)->assertDontSee('name="quantities[', false)
            ->assertSee(__('portal.returns.pure_transport'));
        // A hand-crafted post gets the Chinese domain message, not an English "quantities field is required".
        $this->actingAs($user)->post(route('portal.orders.returns.store', $order), ['reason' => '破损'])
            ->assertRedirect()->assertSessionHasErrors(['return' => __('orders.returns.messages.no_lines')])->assertSessionDoesntHaveErrors('quantities');

        // Staff twin: the same order on /orders has no dead 发起退货 panel either.
        $this->actingAs($this->staff('customer_service'))->get(route('orders.show', $order))->assertOk()
            ->assertDontSee('<summary>'.__('orders.returns.request_title').'</summary>', false)->assertDontSee('name="quantities[', false)
            ->assertSee(__('orders.returns.messages.pure_transport'));
        $this->actingAs($this->staff('customer_service'))->post(route('orders.returns.store', $order), ['reason' => '破损'])
            ->assertRedirect()->assertSessionHasErrors(['return' => __('orders.returns.messages.no_lines')]);
    }

    public function test_an_expired_final_quote_is_marked_and_offers_no_confirm_button(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client);
        $shipment = Shipment::query()->create(['shipment_no' => 'SHP-EXP', 'job_id' => $order->job_id, 'client_id' => $client->id, 'order_id' => $order->id, 'fulfilment_id' => 1, 'shipment_type' => 'outbound', 'status' => 'quoted', 'tailgate_required' => false]);
        $carrier = Carrier::query()->create(['code' => 'OWN-EXP', 'name' => 'Edward Own Fleet', 'status' => 'active']);
        $stale = $this->quote($shipment, $carrier, ['customer_price_cents' => 7500, 'is_recommended' => true, 'quoted_at' => now()->subDays(2), 'expires_at' => now()->subDay()]);
        $fresh = $this->quote($shipment, $carrier, ['customer_price_cents' => 8800, 'service_level' => 'express']);

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee(__('portal.quotes.expired'))->assertDontSee(__('portal.quotes.expired_hint')) // one quote is still confirmable
            ->assertDontSee(route('portal.orders.quotes.confirm', [$order, $stale->id]))
            ->assertSee(route('portal.orders.quotes.confirm', [$order, $fresh->id]));

        $this->actingAs($user)->post(route('portal.orders.quotes.confirm', [$order, $stale->id]))->assertRedirect()->assertSessionHasErrors('quote');
        $this->assertSame('quoted', $stale->fresh()->status);

        // When every quote is stale the client is told to ask for a new one instead of seeing an empty action column.
        $fresh->update(['expires_at' => now()->subHour()]);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()
            ->assertDontSee(__('portal.quotes.confirm'))->assertSee(__('portal.quotes.expired_hint'));
    }

    public function test_a_goods_line_with_only_a_carton_qty_gets_a_chinese_row_error_and_keeps_the_quantity(): void
    {
        $user = $this->clientUser($this->client());

        $this->actingAs($user)->from(route('portal.orders.create'))
            ->post(route('portal.orders.preview'), $this->fromStockPayload(['lines' => [['package_type' => 'carton', 'carton_qty' => 5]]]))
            ->assertRedirect(route('portal.orders.create'))
            ->assertSessionHasErrors(['lines.0.description_cn' => $this->rowMessage('lines.*.description_cn.required_without', 1)])
            ->assertSessionDoesntHaveErrors('lines')
            ->assertSessionHasInput('lines', [['package_type' => 'carton', 'carton_qty' => 5]]);

        // The bounced form shows the typed 箱数 again, once, in Chinese.
        $page = $this->actingAs($user)->get(route('portal.orders.create'))->assertOk();
        $page->assertSee('name="lines[0][carton_qty]" value="5"', false);
    }

    public function test_every_goods_row_carries_the_required_marker_and_added_rows_follow_the_order_type(): void
    {
        $user = $this->clientUser($this->client());

        $page = $this->actingAs($user)->get(route('portal.orders.create'))->assertOk();
        // First row and the <template> row used by 添加货物行 both mark 箱数 / 包装类型 as required.
        $page->assertSee('name="lines[0][carton_qty]" value="" class="goods-required" required', false)
            ->assertSee('name="lines[__INDEX__][carton_qty]" value="" class="goods-required" required', false)
            ->assertSee('name="lines[__INDEX__][package_type]" class="goods-required" aria-label="'.__('portal.fields.package_type').'" required', false)
            ->assertSee("getElementById('add-goods-line')", false); // re-applies the order-type rule to rows added later
        // 要求送达日 cannot be picked in the past client side either.
        $page->assertSee('name="requested_date" value="" min="'.today()->toDateString().'"', false);

        // Server side the second row is named in Chinese with a 1-based row number.
        $this->actingAs($user)->post(route('portal.orders.preview'), $this->fromStockPayload(['lines' => [
            ['description_cn' => '纸箱货', 'package_type' => 'carton', 'carton_qty' => 5],
            ['description_cn' => '配件', 'package_type' => 'carton'],
        ]]))->assertSessionHasErrors(['lines.1.carton_qty' => $this->rowMessage('lines.*.carton_qty.required', 2)]);
    }

    public function test_a_rejected_return_request_keeps_the_reason_and_quantities_and_reopens_the_panel(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client);
        foreach (['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'] as $status) {
            app(OrderStatusService::class)->transitionOperational($order, $status);
        }
        $line = $order->lines->first();

        $page = $this->actingAs($user)->from(route('portal.orders.show', $order))->followingRedirects()
            ->post(route('portal.orders.returns.store', $order), ['quantities' => [$line->id => 0], 'reason' => '外箱破损，货物受潮'])
            ->assertOk();
        $page->assertSee(__('orders.returns.messages.no_lines'))
            ->assertSee('<details id="return-request" open>', false)
            ->assertSee('name="reason" value="外箱破损，货物受潮"', false)
            ->assertSee('name="quantities['.$line->id.']" min="0" max="3" value="0"', false);
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->where('order_type', 'return')->count());

        // A missing reason is named in Chinese.
        $this->actingAs($user)->post(route('portal.orders.returns.store', $order), ['quantities' => [$line->id => 1], 'reason' => ''])
            ->assertRedirect()->assertSessionHasErrors(['reason' => $this->rowMessage('reason.required')]);
    }

    public function test_portal_validation_failures_are_chinese_and_listed_once(): void
    {
        $user = $this->clientUser($this->client());

        $this->actingAs($user)->post(route('portal.orders.preview'), $this->fromStockPayload(['order_type' => 'pickup_deliver', 'lines' => null, 'declared_packages' => [['package_type' => 'carton', 'qty' => 2]]]))
            ->assertSessionHasErrors([
                'pickup_address_line' => $this->rowMessage('pickup_address_line.required_if'),
                'pickup_suburb' => $this->rowMessage('pickup_suburb.required_if'),
            ]);

        // The bounced form prints each message once (layout flash only — no second list on the page).
        $this->actingAs($user)->from(route('portal.orders.create'))
            ->post(route('portal.orders.preview'), $this->fromStockPayload(['lines' => [['description_cn' => '纸箱货', 'package_type' => 'carton']]]))
            ->assertRedirect(route('portal.orders.create'));
        $html = $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, $this->rowMessage('lines.*.carton_qty.required', 1)));
        $this->assertStringNotContainsString('lines.0.carton', $html);
    }

    /** Portal / Orders validation message for a (wildcard) rule key, with the 1-based row position filled in. */
    private function rowMessage(string $key, ?int $position = null): string
    {
        $message = PortalValidation::messages()[$key] ?? $key;

        return $position === null ? $message : str_replace(':position', (string) $position, $message);
    }

    private function order(Client $client, array $overrides = []): Order
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        return app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'AUD-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3, 'actual_weight_kg' => 30]],
        ], $overrides), null, 'manual');
    }

    private function quote(Shipment $shipment, Carrier $carrier, array $attributes = []): TransportQuote
    {
        return TransportQuote::query()->create($attributes + [
            'shipment_id' => $shipment->id, 'carrier_id' => $carrier->id, 'source' => 'own_fleet', 'service_level' => 'standard',
            'cost_cents' => 5000, 'customer_price_cents' => 7500, 'eta_days' => 2, 'quote_stage' => 'final', 'status' => 'quoted',
            'quoted_at' => now(), 'expires_at' => now()->addDay(), 'raw_response' => ['pricing_mode' => 'fixed', '_quote_request' => ['zone' => 'metro', 'items' => []]],
        ]);
    }
}
