<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Services\ApprovalService;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A30 search, A19 approvals (second person), A20 audit log. */
class SearchApprovalActivityTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_global_search_finds_jobs_asns_containers_marks_units_and_clients(): void
    {
        $client = $this->client(['code' => 'EDWARD', 'name' => 'Edward Logistics']);
        $warehouse = $this->warehouse();
        $job = app(JobService::class)->create($client->id, 'container', ['reference' => 'REF-XY-77']);
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'job_id' => $job['job_id'], 'containers' => [['container_no' => 'COSU6508115030', 'size' => '40', 'unpack_mode' => 'loose']]]);
        app(AsnService::class)->addLines($asn, [['container_no' => 'COSU6508115030', 'consignment_mark' => 'GD20260506BC', 'description' => 'motor', 'expected_cartons' => 1]]);
        $cs = $this->staff('customer_service');

        $this->actingAs($cs)->get('/admin/search?q=REF-XY')->assertOk()->assertSee($job['job_no']);
        $this->actingAs($cs)->get('/admin/search?q=COSU6508')->assertOk()->assertSee('COSU6508115030')->assertSee(__('platform.search.types.container'));
        $this->actingAs($cs)->get('/admin/search?q=GD2026')->assertOk()->assertSee('GD20260506BC');
        $this->actingAs($cs)->get('/admin/search?q='.$asn->asn_no)->assertOk()->assertSee($asn->asn_no);
        $this->actingAs($cs)->get('/admin/search?q=edward')->assertOk()->assertSee('Edward Logistics');
        $this->actingAs($cs)->get('/admin/search?q=zzzz-none')->assertOk()->assertSee(__('platform.search.empty', ['q' => 'zzzz-none']));
        $this->actingAs($cs)->get('/admin/search?q=x')->assertOk()->assertSee(__('platform.search.hint'));
        // Tester feedback #3: several keywords in any order, no dashes needed, case-insensitive.
        $this->actingAs($cs)->get('/admin/search?q=6508115030 cosu')->assertOk()->assertSee('COSU6508115030');
        $this->actingAs($cs)->get('/admin/search?q=EDWARD logistics')->assertOk()->assertSee('Edward Logistics');
    }

    public function test_approvals_need_a_second_person(): void
    {
        $client = $this->client();
        $requester = $this->staff('finance');
        $other = $this->staff('finance');
        $service = app(ApprovalService::class);

        $approval = $service->request('credit_note', 'invoice', 12, $requester, ['client_id' => $client->id, 'request_note' => 'damaged goods', 'payload' => ['amount_cents' => 12500]]);
        $this->assertSame($approval->id, $service->request('credit_note', 'invoice', 12, $requester)->id); // one open request per subject

        try {
            $service->approve($approval, $requester);
            $this->fail('requester must not approve their own request');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('second person', $e->getMessage());
        }
        $this->assertFalse($service->isApproved('credit_note', 'invoice', 12));

        $this->actingAs($requester)->get('/admin/approvals')->assertOk()->assertSee('damaged goods');
        $this->actingAs($requester)->post("/admin/approvals/{$approval->id}/approve")->assertSessionHasErrors('approval');
        $this->actingAs($this->staff('dispatcher'))->post("/admin/approvals/{$approval->id}/approve")->assertForbidden();
        $this->actingAs($other)->post("/admin/approvals/{$approval->id}/approve", ['note' => 'ok'])->assertRedirect();

        $this->assertTrue($service->isApproved('credit_note', 'invoice', 12));
        $this->assertDatabaseHas('approvals', ['id' => $approval->id, 'status' => 'approved', 'decided_by' => $other->id, 'decision_note' => 'ok']);
        $this->actingAs($other)->post("/admin/approvals/{$approval->id}/reject")->assertSessionHasErrors('approval'); // already decided

        $second = $service->request('stock_adjustment', 'stocktake', 3, $requester);
        $this->actingAs($requester)->post("/admin/approvals/{$second->id}/cancel")->assertRedirect();
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertSame(1, Approval::query()->where('status', 'approved')->count());
    }

    public function test_changes_are_audited_without_passwords(): void
    {
        $admin = $this->staff();
        $client = $this->client(['name' => 'Before Pty Ltd']);

        $this->actingAs($admin)->put("/admin/clients/{$client->id}", [
            'code' => $client->code, 'name' => 'After Pty Ltd', 'leg_type' => 'both', 'status' => 'active', 'payment_terms' => 'net_14', 'invoice_mode' => 'monthly', 'default_markup_percent' => 20,
        ])->assertRedirect();

        $activity = Activity::query()->where('subject_type', $client::class)->where('subject_id', $client->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, (int) $activity->causer_id);
        $this->assertSame('After Pty Ltd', $activity->properties['attributes']['name']);
        $this->assertSame('Before Pty Ltd', $activity->properties['old']['name']);
        $this->assertSame('net_14', $activity->properties['attributes']['payment_terms']);

        $user = $this->staff('dispatcher');
        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['name' => 'Renamed', 'email' => $user->email, 'password' => 'new-secret-99', 'role' => 'dispatcher', 'is_active' => 1])->assertRedirect();
        $userActivity = Activity::query()->where('subject_type', $user::class)->where('subject_id', $user->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('password', $userActivity->properties['attributes']);
        $this->assertSame('Renamed', $userActivity->properties['attributes']['name']);

        $this->actingAs($admin)->get('/admin/activity')->assertOk()->assertSee('After Pty Ltd')->assertSee($admin->name);
        $this->actingAs($admin)->get('/admin/activity?subject_type='.urlencode($client::class))->assertOk()->assertSee('Before Pty Ltd');
        $this->actingAs($this->staff('finance'))->get('/admin/activity')->assertForbidden();
    }
}
