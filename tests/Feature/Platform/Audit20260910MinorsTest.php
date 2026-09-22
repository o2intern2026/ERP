<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Services\ApprovalService;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Blocking-bug audit 2026-09-10, Platform minors: no link or button that the reader's role cannot use (Job workbench, Exception
 * Centre, Approval Centre), client-visible documents always have a client, the user form forces an explicit role.
 */
class Audit20260910MinorsTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_job_workbench_links_follow_the_reader_role(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $jobId = $asn->job_id;
        app(ChargeEngine::class)->manual($jobId, $client->id, 'WH-PUTAWAY-PLT', 2, 'demo', null, $this->staff('finance')->id);
        $job = Job::query()->findOrFail($jobId);

        // customer_service sees the charges panel but /billing is admin | finance: the 打开 drill-through is not offered.
        $this->actingAs($this->staff('customer_service'))->get(route('platform.jobs.show', $job))->assertOk()
            ->assertSee(__('billing.job_panel.title'))->assertDontSee(route('billing.index', ['job_no' => $job->job_no]))
            ->assertSee(route('warehouse.asns.show', $asn->id)); // …while the warehouse links are fine for this role
        $this->actingAs($this->staff('finance'))->get(route('platform.jobs.show', $job))->assertOk()->assertSee(route('billing.index', ['job_no' => $job->job_no]));

        // transport_operator is outside the Warehouse read group: ASN number as text, no 打开库存 link, no 403 waiting behind a click.
        $this->actingAs($this->staff('transport_operator'))->get(route('platform.jobs.show', $job))->assertOk()
            ->assertSee($asn->asn_no)->assertDontSee(route('warehouse.asns.show', $asn->id))->assertDontSee(__('platform.jobs.open_stock'));
    }

    public function test_exception_centre_source_link_only_where_the_role_may_open_it(): void
    {
        $client = $this->client();
        $service = app(ExceptionService::class);
        $service->raise('discrepancy', 'warehouse', ['client_id' => $client->id, 'source_type' => 'asn', 'source_id' => 7, 'message' => 'asn source']);
        $service->raise('integration_failed', 'platform', ['client_id' => $client->id, 'source_type' => 'outbox_event', 'source_id' => 9, 'message' => 'event source']);

        $this->actingAs($this->staff('transport_operator'))->get('/admin/exceptions')->assertOk()->assertSee('asn source')
            ->assertDontSee(route('warehouse.asns.show', 7))->assertDontSee(route('platform.integration.index'));
        $this->actingAs($this->staff('customer_service'))->get('/admin/exceptions')->assertOk()
            ->assertSee(route('warehouse.asns.show', 7))->assertDontSee(route('platform.integration.index'));
        $this->actingAs($this->staff('admin'))->get('/admin/exceptions')->assertOk()->assertSee(route('platform.integration.index'));
    }

    public function test_requester_sees_no_decision_buttons_and_refusals_are_in_chinese(): void
    {
        $client = $this->client();
        $requester = $this->staff('finance');
        $other = $this->staff('finance');
        $approval = app(ApprovalService::class)->request('credit_note', 'credit_note', 12, $requester, ['client_id' => $client->id, 'request_note' => 'damaged goods']);

        $this->actingAs($requester)->get('/admin/approvals')->assertOk()->assertSee('damaged goods')
            ->assertDontSee('action="'.route('platform.approvals.approve', $approval).'"', false)
            ->assertSee(__('platform.approvals.awaiting_second_person'))
            ->assertSee('action="'.route('platform.approvals.cancel', $approval).'"', false);
        $this->actingAs($requester)->post(route('platform.approvals.approve', $approval))->assertSessionHasErrors(['approval' => __('platform.approvals.errors.self_decide')]);

        $this->actingAs($other)->get('/admin/approvals')->assertOk()->assertSee('action="'.route('platform.approvals.approve', $approval).'"', false)->assertDontSee(__('platform.approvals.awaiting_second_person'));
        $this->actingAs($other)->post(route('platform.approvals.approve', $approval), ['note' => 'ok'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($other)->post(route('platform.approvals.reject', $approval))->assertSessionHasErrors(['approval' => __('platform.approvals.errors.already_decided', ['id' => $approval->id, 'status' => __('platform.approvals.statuses.approved')])]);
    }

    public function test_client_visible_documents_always_have_a_client(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose');
        $cs = $this->staff('customer_service');
        // CR #137 (audit ADMIN-10): the form takes a 单号; the client comes from the number's object, never from a typed id.
        $upload = fn (array $extra) => $this->actingAs($cs)->from('/admin/documents')->post('/admin/documents', $extra + ['file' => UploadedFile::fake()->create('pod.pdf', 10, 'application/pdf'), 'type' => 'pod', 'document_no' => $job['job_no']]);

        // An unknown number is refused with a Chinese message and the form keeps its values.
        $upload(['client_visible' => 1, 'document_no' => 'ORD-20260101-0044'])->assertRedirect('/admin/documents')->assertSessionHasErrors(['document_no' => __('platform.documents.number_not_found', ['no' => 'ORD-20260101-0044'])])->assertSessionHasInput('document_no', 'ORD-20260101-0044');
        $this->assertSame(0, Document::query()->count());
        $this->actingAs($cs)->get('/admin/documents')->assertOk()->assertSee('<details open>', false)->assertSee('value="ORD-20260101-0044"', false);

        // The Job supplies the client, so 客户可见 is possible from the form.
        $upload(['client_visible' => 1])->assertRedirect('/admin/documents')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('documents', ['job_id' => $job['job_id'], 'client_id' => $client->id, 'client_visible' => true]);

        // An internal document without a client (written by a module through DocumentService) cannot be flipped to visible from the list:
        // the button is replaced by the reason, the POST is refused.
        $internal = Document::query()->create(['type' => 'photo', 'related_type' => 'other', 'related_id' => 1, 'client_id' => null, 'client_visible' => false, 'storage_path' => 'documents/internal.jpg']);
        $this->actingAs($cs)->get('/admin/documents')->assertOk()->assertSee(__('platform.documents.no_client'))->assertDontSee('action="'.route('platform.documents.visibility', $internal).'"', false);
        $this->actingAs($cs)->post(route('platform.documents.visibility', $internal), ['client_visible' => 1])->assertSessionHasErrors(['client_visible' => __('platform.documents.client_required')]);
        $this->assertFalse($internal->fresh()->client_visible);
    }

    public function test_user_form_forces_an_explicit_role(): void
    {
        $admin = $this->staff();

        $this->actingAs($admin)->get('/admin/users/create')->assertOk()->assertSee('<option value="" selected>'.__('platform.users.role_placeholder'), false)->assertDontSee('<option value="admin" selected>', false);
        $this->actingAs($admin)->post('/admin/users', ['name' => '测试仓管', 'email' => 'op-test@example.com', 'password' => 'password123', 'role' => ''])->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'op-test@example.com']);

        // An existing user without a role is not silently promoted either.
        $roleless = User::factory()->create();
        $this->actingAs($admin)->get("/admin/users/{$roleless->id}/edit")->assertOk()->assertSee('<option value="" selected>', false);
        $this->actingAs($admin)->put("/admin/users/{$roleless->id}", ['name' => $roleless->name, 'email' => $roleless->email, 'role' => '', 'is_active' => 1])->assertSessionHasErrors('role');
        $this->assertFalse($roleless->fresh()->hasRole('admin'));
    }
}
