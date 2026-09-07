<?php

namespace Tests\Feature\Platform;

use App\Support\Contracts\ExceptionService;
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
}
