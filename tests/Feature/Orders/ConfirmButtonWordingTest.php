<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 2026-09-14 lead feedback: the order page's confirm button read "确认订单并检查库存" for every order type, and the flash /
 * timeline said "waiting for WMS to lock stock" — wrong for 提货直送, which never touches stock and goes straight to a
 * final transport quote. The wording now follows the order type; from_stock keeps the original text.
 */
class ConfirmButtonWordingTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_pickup_deliver_confirm_names_the_transport_plan_not_a_stock_check(): void
    {
        $dispatcher = $this->staff('dispatcher');
        $client = $this->client();

        $this->actingAs($dispatcher)->post('/orders', $this->pickupPayload($client))->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($dispatcher)->get(route('orders.show', $order))->assertOk()
            ->assertSee('确认订单并生成运输方案')
            ->assertDontSee('检查库存');

        $this->actingAs($dispatcher)->post(route('orders.confirm', $order))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('orders.messages.confirmed_pickup_deliver'));
        $this->assertSame('confirmed', $order->fresh()->operational_status);

        $page = $this->actingAs($dispatcher)->get(route('orders.show', $order))->assertOk();
        $page->assertSee(__('orders.fulfilments.timeline.confirmed_pickup_deliver'));
        $this->assertStringNotContainsString('锁定实际库存', $page->getContent());
    }

    public function test_from_stock_confirm_keeps_the_stock_check_wording(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();

        $this->actingAs($cs)->post('/orders', [
            'client_id' => $client->id, 'job_id' => null, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_phone' => '0400000000', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business',
            'requested_date' => '2026-09-30', 'service_level' => 'standard',
            'lines' => [['description_cn' => '灯具', 'package_type' => 'carton', 'carton_qty' => 2, 'actual_weight_kg' => 10]],
        ])->assertSessionHasNoErrors();
        $order = Order::query()->sole();

        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()
            ->assertSee(__('orders.actions.confirm'))
            ->assertDontSee(__('orders.actions.confirm_pickup_deliver'));
    }

    private function pickupPayload(Client $client): array
    {
        return [
            'client_id' => $client->id, 'job_id' => null, 'order_type' => 'pickup_deliver', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_phone' => '0400000000', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business',
            'requested_date' => '2026-09-30', 'service_level' => 'standard',
            'pickup_name' => 'Factory', 'pickup_phone' => '0400000000', 'pickup_address_line' => '9 Supplier Rd', 'pickup_suburb' => 'Laverton', 'pickup_state' => 'VIC', 'pickup_postcode' => '3028',
            'declared_packages' => [['package_type' => 'carton', 'qty' => 3, 'weight_kg' => 12, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 300]],
        ];
    }
}
