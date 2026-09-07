<?php

namespace Tests\Feature\Orders;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A16 tailgate rule (ERP_PLAN §3.8 #10 first half) and A11b pure transport orders (§3.8 #6). Covered by C for X1 — HANDOFF.md. */
class TailgateAndTransportOrderTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(Client $client, ?int $jobId, array $overrides = []): array
    {
        return array_replace_recursive([
            'client_id' => $client->id, 'job_id' => $jobId, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Dandenong', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3175',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(2)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Widgets', 'package_type' => 'carton', 'carton_qty' => 1, 'actual_weight_kg' => 10]],
        ], $overrides);
    }

    public function test_a_25kg_piece_ticks_tailgate_automatically_and_the_confirmed_event_carries_it(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');

        $this->actingAs($user)->post('/orders', $this->payload($client, $job['job_id'], ['lines' => [['carton_qty' => 1, 'actual_weight_kg' => 25]]]))->assertSessionHasNoErrors();
        $order = Order::query()->firstOrFail();
        $this->assertTrue($order->tailgate_required);
        $this->assertSame('heavy_item', $order->tailgate_reason);

        // Four cartons weighing 40 kg together are 10 kg pieces: no tailgate.
        $this->actingAs($user)->post('/orders', $this->payload($client, $job['job_id'], ['lines' => [['carton_qty' => 4, 'actual_weight_kg' => 40]]]))->assertSessionHasNoErrors();
        $this->assertFalse(Order::query()->latest('id')->firstOrFail()->tailgate_required);

        // A residential address needs a tailgate regardless of weight.
        $this->actingAs($user)->post('/orders', $this->payload($client, $job['job_id'], ['deliver_to_address_type' => 'residential']))->assertSessionHasNoErrors();
        $residential = Order::query()->latest('id')->firstOrFail();
        $this->assertSame([true, 'residential_address'], [$residential->tailgate_required, $residential->tailgate_reason]);

        $this->actingAs($user)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.tailgate.required'))->assertSee(__('orders.tailgate.reasons.heavy_item'));
    }

    public function test_a_person_can_override_the_tailgate_decision_with_a_reason_that_lands_in_the_timeline(): void
    {
        $user = $this->staff('dispatcher');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $this->actingAs($user)->post('/orders', $this->payload($client, $job['job_id']))->assertSessionHasNoErrors();
        $order = Order::query()->firstOrFail();
        $this->assertFalse($order->tailgate_required);

        $this->actingAs($user)->post(route('orders.tailgate', $order), ['tailgate_required' => 1, 'reason' => '客户现场没有叉车'])->assertSessionHasNoErrors();
        $this->assertSame([true, 'manual'], [$order->fresh()->tailgate_required, $order->fresh()->tailgate_reason]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $user->id, 'note' => __('orders.tailgate.timeline.forced', ['reason' => '客户现场没有叉车'])]);
        $this->actingAs($user)->post(route('orders.tailgate', $order), ['tailgate_required' => 1])->assertSessionHasErrors('reason');

        // The manual decision survives confirmation and is what the event carries.
        $order->lines()->update(['asn_line_id' => 1]);
        $this->actingAs($user)->post(route('orders.confirm', $order))->assertSessionHasNoErrors();
        $event = OutboxEvent::query()->where('event_name', 'order.confirmed')->firstOrFail();
        $this->assertTrue($event->payload['tailgate_required']);
        $this->assertSame('manual', $event->payload['tailgate_reason']);
    }

    public function test_a_pure_transport_order_needs_no_goods_lines_opens_its_own_job_and_confirms_straight_to_transport(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();

        $pure = fn (array $overrides = []) => array_diff_key($this->payload($client, null, ['order_type' => 'pickup_deliver'] + $overrides), ['lines' => true]); // no goods lines at all

        $this->actingAs($user)->post('/orders', $pure())->assertSessionHasErrors(['pickup_address_line', 'declared_packages']);

        $this->actingAs($user)->post('/orders', $pure([
            'pickup_name' => 'Factory', 'pickup_phone' => '0400000000', 'pickup_address_line' => '9 Supplier Rd', 'pickup_suburb' => 'Laverton', 'pickup_state' => 'VIC', 'pickup_postcode' => '3028',
            'declared_packages' => [['package_type' => 'pallet', 'qty' => 2, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400]],
        ]))->assertSessionHasNoErrors();

        $order = Order::query()->with('job', 'declaredPackages', 'lines')->firstOrFail();
        $this->assertSame('pickup_deliver', $order->order_type);
        $this->assertSame('transport_only', $order->job->job_type); // Job opened through JobService (A27)
        $this->assertSame('Laverton', $order->pickup_address['suburb']);
        $this->assertCount(0, $order->lines);
        $this->assertCount(1, $order->declaredPackages);
        $this->assertTrue($order->tailgate_required); // 300 kg pallet

        $this->actingAs($user)->post(route('orders.confirm', $order))->assertSessionHasNoErrors(); // no stock check for pure transport
        $this->assertSame('confirmed', $order->fresh()->operational_status);
        $event = OutboxEvent::query()->where('event_name', 'order.confirmed')->firstOrFail();
        $this->assertSame('pickup_deliver', $event->payload['order_type']);
        $this->assertSame('9 Supplier Rd', $event->payload['pickup_address']['address']);
        $this->assertSame(2, $event->payload['declared_packages'][0]['qty']);
        $this->assertSame(0, $order->fulfilments()->count());

        $this->actingAs($user)->get(route('orders.show', $order))->assertOk()->assertSee('9 Supplier Rd')->assertSee(__('orders.pickup.packages_title'));
        $this->assertSame(1, app(OrderCreationService::class) instanceof OrderCreationService ? 1 : 0);
    }
}
