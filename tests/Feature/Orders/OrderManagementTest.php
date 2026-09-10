<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderStatusService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A3: manual entry, three independent statuses, append-only timeline and the picking edit lock. */
class OrderManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_customer_service_can_create_and_view_a_manual_order_with_goods(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client(['name' => 'Edward Logistics Client']);
        $job = app(JobService::class)->create($client->id, 'loose', ['reference' => 'IMPORT-88']);

        $response = $this->actingAs($user)->post('/orders', $this->validPayload($client, $job['job_id']));

        $order = Order::query()->with('lines')->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order));
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-0001$/', $order->order_no);
        $this->assertSame($client->id, $order->client_id);
        $this->assertSame($job['job_id'], $order->job_id);
        $this->assertSame('manual', $order->source);
        $this->assertSame(['received', 'unfulfilled', 'unbilled'], [
            $order->operational_status,
            $order->fulfilment_status,
            $order->billing_status,
        ]);
        $this->assertSame(12, $order->lines->first()->carton_qty);
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'dimension' => 'operational',
            'from_status' => null,
            'to_status' => 'received',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertSee($order->order_no)
            ->assertSee('Edward Logistics Client')
            ->assertSee('MARK-001');

        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('IMPORT-88')
            ->assertSee('电商展示架')
            ->assertSee($user->name);
    }

    /** ERP_PLAN §3.8 #3: operational and billing status are separate, and every move records actor/history. */
    public function test_operational_and_billing_statuses_progress_independently_with_a_complete_timeline(): void
    {
        $user = $this->staff('customer_service', ['name' => 'OMS Operator']);
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $order = $this->createOrder($user, $client, $job['job_id']);
        $workflow = app(OrderStatusService::class);

        foreach (['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'] as $status) {
            $order = $workflow->transitionOperational($order, $status, $user->id, 'status '.$status);
        }

        $this->assertSame('delivered', $order->operational_status);
        $this->assertSame('unbilled', $order->billing_status);

        $order = $workflow->transitionBilling($order, 'billed', $user->id, 'invoice issued');

        $this->assertSame('delivered', $order->operational_status);
        $this->assertSame('billed', $order->billing_status);
        $this->assertSame('invoiced', $order->customerStatus());
        $this->assertSame(8, $order->events()->count());
        $this->assertSame(7, $order->events()->where('dimension', 'operational')->count());
        $this->assertSame(1, $order->events()->where('dimension', 'billing')->count());
        $this->assertSame(8, $order->events()->where('actor_id', $user->id)->count());

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'order.confirmed', // delivered immediately since CR #105, so no status assertion here
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
        ]);

        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(__('orders.statuses.operational.delivered'))
            ->assertSee(__('orders.statuses.billing.billed'))
            ->assertSee(__('orders.customer_statuses.invoiced'))
            ->assertSee('OMS Operator')
            ->assertSeeInOrder([
                __('orders.statuses.operational.received'),
                __('orders.statuses.operational.confirmed'),
                __('orders.statuses.operational.allocated'),
                __('orders.statuses.operational.picking'),
                __('orders.statuses.operational.packed'),
                __('orders.statuses.operational.dispatched'),
                __('orders.statuses.operational.delivered'),
                __('orders.statuses.billing.billed'),
            ]);
    }

    /** ERP_PLAN §3.8 #4: after dispatch, ordinary edits are forbidden; the later A11 return flow is required. */
    public function test_a_dispatched_order_cannot_be_edited(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $order = $this->createOrder($user, $client, $job['job_id']);
        $workflow = app(OrderStatusService::class);

        foreach (['confirmed', 'allocated', 'picking', 'packed', 'dispatched'] as $status) {
            $order = $workflow->transitionOperational($order, $status, $user->id);
        }

        $this->actingAs($user)->patch(route('orders.update', $order), [
            'deliver_to_name' => 'Changed Recipient',
            'deliver_to_phone' => '0400000000',
            'deliver_to_address' => '99 Wrong Street',
            'deliver_to_suburb' => 'Melbourne',
            'deliver_to_state' => 'VIC',
            'deliver_to_postcode' => '3000',
            'requested_date' => '2026-09-30',
        ])->assertForbidden();

        $this->assertSame('1 Warehouse Way', $order->fresh()->deliver_to_address);
        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(__('orders.changes.hint_shipped')) // A11: shipped orders only accept a return
            ->assertDontSee(__('orders.actions.edit_delivery'));
    }

    private function createOrder(User $user, Client $client, int $jobId): Order
    {
        $this->actingAs($user)->post('/orders', $this->validPayload($client, $jobId))
            ->assertSessionHasNoErrors();

        return Order::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function validPayload(Client $client, int $jobId): array
    {
        return [
            'client_id' => $client->id,
            'job_id' => $jobId,
            'order_type' => 'from_stock',
            'external_ref' => 'PO-10001',
            'consignment_mark' => 'MARK-001',
            'fba_reference' => 'FBA-ABC-1',
            'deliver_to_name' => 'Amazon BWU2',
            'deliver_to_phone' => '0299999999',
            'deliver_to_address' => '1 Warehouse Way',
            'deliver_to_suburb' => 'Kemps Creek',
            'deliver_to_state' => 'NSW',
            'deliver_to_postcode' => '2178',
            'deliver_to_address_type' => 'fba',
            'requested_date' => '2026-09-30',
            'service_level' => 'standard',
            'lines' => [[
                'description_cn' => '电商展示架',
                'description_en' => 'Retail display stand',
                'package_type' => 'carton',
                'carton_qty' => 12,
                'unit_qty' => 24,
                'actual_weight_kg' => 18.5,
                'length_mm' => 600,
                'width_mm' => 400,
                'height_mm' => 350,
                'cbm' => 0.084,
            ]],
        ];
    }
}
