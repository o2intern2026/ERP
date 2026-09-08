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

    /** Tester feedback item 6: the form's 尾板车 checkbox — automatic unless a person changed it (tailgate_manual → reason manual). */
    public function test_the_order_form_checkbox_overrides_the_automatic_tailgate_rule_only_when_a_person_changed_it(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        $this->actingAs($user)->get(route('orders.create'))->assertOk()
            ->assertSee('name="tailgate_required"', false)->assertSee('name="tailgate_manual"', false)->assertSee('id="tailgate-required"', false)
            ->assertSee(__('orders.tailgate.form_label'))->assertSee(__('orders.tailgate.form_hint', ['kg' => 25]))->assertSee('data-tailgate-kg="25"', false);

        $heavy = ['lines' => [['carton_qty' => 2, 'actual_weight_kg' => 60]]]; // 30 kg pieces

        // Untouched checkbox (browser sends the hidden 0 / auto-ticked 1, manual 0): the automatic rule decides.
        $this->actingAs($user)->post('/orders', $this->payload($client, $job, $heavy + ['tailgate_required' => 1, 'tailgate_manual' => 0]))->assertSessionHasNoErrors();
        $auto = Order::query()->latest('id')->firstOrFail();
        $this->assertSame([true, 'heavy_item'], [$auto->tailgate_required, $auto->tailgate_reason]);

        // The person unticked the auto-ticked box: stays off with reason manual, survives confirmation, is what the event carries.
        $this->actingAs($user)->post('/orders', $this->payload($client, $job, $heavy + ['tailgate_required' => 0, 'tailgate_manual' => 1]))->assertSessionHasNoErrors();
        $off = Order::query()->latest('id')->firstOrFail();
        $this->assertSame([false, 'manual'], [$off->tailgate_required, $off->tailgate_reason]);
        $this->assertDatabaseHas('order_events', ['order_id' => $off->id, 'actor_id' => $user->id, 'note' => __('orders.tailgate.timeline.manual_off_entry')]);
        $off->lines()->update(['asn_line_id' => 1]);
        $this->actingAs($user)->post(route('orders.confirm', $off))->assertSessionHasNoErrors();
        $this->assertSame([false, 'manual'], [$off->fresh()->tailgate_required, $off->fresh()->tailgate_reason]);
        $event = OutboxEvent::query()->where('event_name', 'order.confirmed')->where('payload->order_id', $off->id)->firstOrFail();
        $this->assertFalse($event->payload['tailgate_required']);
        $this->assertSame('manual', $event->payload['tailgate_reason']);

        // Light order, person ticked the box: on with reason manual.
        $this->actingAs($user)->post('/orders', $this->payload($client, $job, ['tailgate_required' => 1, 'tailgate_manual' => 1]))->assertSessionHasNoErrors();
        $on = Order::query()->latest('id')->firstOrFail();
        $this->assertSame([true, 'manual'], [$on->tailgate_required, $on->tailgate_reason]);
        $this->assertDatabaseHas('order_events', ['order_id' => $on->id, 'note' => __('orders.tailgate.timeline.manual_on_entry')]);
        $this->actingAs($user)->get(route('orders.show', $on))->assertOk()->assertSee(__('orders.tailgate.required'))->assertSee(__('orders.tailgate.reasons.manual'));

        // Portal form: same checkbox, same rule; the client's order page shows the flag.
        $portalUser = $this->clientUser($client);
        $this->actingAs($portalUser)->get(route('portal.orders.create'))->assertOk()->assertSee('id="tailgate-required"', false)->assertSee(__('orders.tailgate.form_hint', ['kg' => 25]));
        $portal = fn (array $extra) => [
            'order_type' => 'from_stock', 'external_ref' => 'PORTAL-TG-'.uniqid(), 'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000', 'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '灯具', 'package_type' => 'carton', 'carton_qty' => 1, 'actual_weight_kg' => 5]],
        ] + $extra;
        $this->actingAs($portalUser)->post(route('portal.orders.store'), $portal(['tailgate_required' => 1, 'tailgate_manual' => 1]))->assertSessionHasNoErrors();
        $portalOn = Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame([true, 'manual', 'portal'], [$portalOn->tailgate_required, $portalOn->tailgate_reason, $portalOn->source]);
        $this->actingAs($portalUser)->get(route('portal.orders.show', $portalOn))->assertOk()->assertSee(__('portal.fields.tailgate'))->assertSee(__('portal.tailgate.required'));
        $this->actingAs($portalUser)->post(route('portal.orders.store'), $portal(['tailgate_required' => 0, 'tailgate_manual' => 0]))->assertSessionHasNoErrors();
        $portalAuto = Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame([false, null], [$portalAuto->tailgate_required, $portalAuto->tailgate_reason]);
        $this->actingAs($portalUser)->get(route('portal.orders.show', $portalAuto))->assertOk()->assertSee(__('portal.tailgate.not_required'));
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
