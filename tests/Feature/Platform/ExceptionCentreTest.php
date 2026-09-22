<?php

namespace Tests\Feature\Platform;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderHoldService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A28: one list for every module's exceptions; take → start → resolve; holds are released on resolve; clients cannot see it. */
class ExceptionCentreTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_staff_work_an_exception_from_the_centre(): void
    {
        $client = $this->client();
        $service = app(ExceptionService::class);
        $discrepancy = $service->raise('discrepancy', 'warehouse', ['client_id' => $client->id, 'source_type' => 'asn_line', 'source_id' => 1, 'message' => 'expected 10, received 9']);
        $hold = $service->raise('hold', 'orders', ['client_id' => $client->id, 'hold_type' => 'financial', 'order_id' => 5, 'message' => 'overdue']);
        $cs = $this->staff('customer_service');
        $finance = $this->staff('finance');

        $this->actingAs($cs)->get('/admin/exceptions')->assertOk()->assertSee('expected 10, received 9')->assertSee(__('platform.exceptions.hold_types.financial'));
        $this->actingAs($cs)->get('/admin/exceptions?type=hold')->assertOk()->assertDontSee('expected 10, received 9');

        $this->actingAs($cs)->post("/admin/exceptions/{$discrepancy}/assign")->assertRedirect();
        $this->assertDatabaseHas('exceptions', ['id' => $discrepancy, 'owner_id' => $cs->id, 'status' => 'open']);
        $this->actingAs($cs)->post("/admin/exceptions/{$discrepancy}/start")->assertRedirect();
        $this->assertDatabaseHas('exceptions', ['id' => $discrepancy, 'status' => 'in_progress']);
        $this->actingAs($cs)->get('/admin/exceptions?owner=me')->assertOk()->assertSee('expected 10, received 9');
        $this->actingAs($cs)->post("/admin/exceptions/{$discrepancy}/resolve", ['note' => 'carton found'])->assertRedirect();
        $this->assertDatabaseHas('exceptions', ['id' => $discrepancy, 'status' => 'resolved', 'resolved_by' => $cs->id]);

        // A hold needs a note (it becomes the release reason) and releasing it lifts the block.
        $this->actingAs($finance)->post("/admin/exceptions/{$hold}/resolve", [])->assertSessionHasErrors('note');
        $this->assertTrue($service->hasActiveHold('financial', $client->id));
        $this->actingAs($finance)->post("/admin/exceptions/{$hold}/assign", ['owner_id' => $cs->id])->assertRedirect();
        $this->assertDatabaseHas('exceptions', ['id' => $hold, 'owner_id' => $cs->id]);
        $this->actingAs($finance)->post("/admin/exceptions/{$hold}/resolve", ['note' => 'paid'])->assertRedirect();
        $this->assertFalse($service->hasActiveHold('financial', $client->id));
        $this->assertDatabaseHas('exceptions', ['id' => $hold, 'released_by' => $finance->id, 'release_reason' => 'paid']);

        $this->actingAs($cs)->get('/admin/exceptions?status=resolved')->assertOk()->assertSee('paid');
        $this->actingAs($this->clientUser($client))->get('/admin/exceptions')->assertForbidden();
    }

    /**
     * Audit 2026-09-22 ADMIN-01: the financial hold is the only payment control (AGENTS.md — unpaid invoices never block dispatch), so the
     * centre must not let any staff role release it. Releasing from the centre needs the order page's roles (Orders' OrderHoldService::rolesFor:
     * admin / finance / dispatcher) and goes through the same Orders service, so the order timeline carries the release like the order page does.
     */
    public function test_a_financial_hold_is_released_from_the_centre_only_by_the_order_page_roles_and_lands_in_the_order_timeline(): void
    {
        $client = $this->client();
        $service = app(ExceptionService::class);
        $finance = $this->staff('finance');
        $operator = $this->staff('warehouse_operator');
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $order = Order::query()->create([
            'order_no' => 'ORD-HOLD-1', 'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'source' => 'manual', 'consignment_mark' => 'HOLD-MARK',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'requested_date' => today()->addDay()->toDateString(), 'operational_status' => 'confirmed',
        ]);
        $hold = app(OrderHoldService::class)->place($order, 'financial', 'overdue', $finance->id); // exactly what the order page does
        $released = __('orders.holds.timeline.released', ['type' => __('orders.holds.types.financial'), 'note' => 'paid']);

        // A warehouse operator sees the row but no forms — only the link to the order page — and every POST is a 403; the hold stays.
        $this->actingAs($operator)->get('/admin/exceptions?type=hold')->assertOk()->assertSee('overdue')
            ->assertSee(__('platform.exceptions.to_order_release'))->assertSee(__('platform.roles.finance'))
            ->assertDontSee("/admin/exceptions/{$hold}/resolve")->assertDontSee("/admin/exceptions/{$hold}/assign")->assertDontSee("/admin/exceptions/{$hold}/start");
        $this->actingAs($operator)->post("/admin/exceptions/{$hold}/resolve", ['note' => 'let me through'])->assertForbidden();
        $this->actingAs($operator)->post("/admin/exceptions/{$hold}/assign")->assertForbidden();
        $this->actingAs($operator)->post("/admin/exceptions/{$hold}/start")->assertForbidden();
        $this->actingAs($this->staff('customer_service'))->post("/admin/exceptions/{$hold}/resolve", ['note' => 'cs cannot'])->assertForbidden(); // other holds yes, the financial one no
        $this->assertTrue($service->hasActiveHold('financial', $client->id, $order->id));
        $this->assertDatabaseHas('exceptions', ['id' => $hold, 'status' => 'open', 'released_by' => null]);
        $this->assertDatabaseMissing('order_events', ['order_id' => $order->id, 'note' => __('orders.holds.timeline.released', ['type' => __('orders.holds.types.financial'), 'note' => 'let me through'])]);

        // Finance (like admin / dispatcher) sees the forms and releases it; the order timeline shows the release with the actor, as on the order page.
        $this->actingAs($finance)->get('/admin/exceptions?type=hold')->assertOk()->assertSee("/admin/exceptions/{$hold}/resolve")->assertDontSee(__('platform.exceptions.to_order_release'));
        $this->actingAs($finance)->post("/admin/exceptions/{$hold}/resolve", ['note' => 'paid'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($service->hasActiveHold('financial', $client->id, $order->id));
        $this->assertDatabaseHas('exceptions', ['id' => $hold, 'status' => 'resolved', 'resolved_by' => $finance->id, 'released_by' => $finance->id, 'release_reason' => 'paid']);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_type' => 'user', 'actor_id' => $finance->id, 'note' => $released]);
        $this->actingAs($finance)->post("/admin/exceptions/{$hold}/resolve", ['note' => 'again'])->assertSessionHasErrors('note'); // no longer active (Orders' own refusal)

        // A non-hold row keeps its forms for every staff role, as before.
        $discrepancy = $service->raise('discrepancy', 'warehouse', ['client_id' => $client->id, 'source_type' => 'asn_line', 'source_id' => 1, 'message' => 'short by one']);
        $this->actingAs($operator)->get('/admin/exceptions')->assertOk()->assertSee("/admin/exceptions/{$discrepancy}/resolve");
        $this->actingAs($operator)->post("/admin/exceptions/{$discrepancy}/resolve", ['note' => 'found'])->assertRedirect();
        $this->assertDatabaseHas('exceptions', ['id' => $discrepancy, 'status' => 'resolved', 'resolved_by' => $operator->id]);
    }
}
