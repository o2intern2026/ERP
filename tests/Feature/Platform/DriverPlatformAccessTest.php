<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Services\JobService;
use App\Support\Contracts\JobService as JobServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/**
 * CR #137 (audit ADMIN-08 / CRAWL-05 / TMS-15, extending CR #130 "the driver executes"): the driver lands on /driver, never sees
 * cost / margin / invoice money on the Job page, cannot open Jobs or upload documents — and keeps read-only access everywhere else.
 */
class DriverPlatformAccessTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_a_driver_only_user_lands_on_the_driver_page_after_login_and_from_the_root(): void
    {
        $this->get('/')->assertRedirect('/jobs'); // a guest goes on to /jobs (→ /login), as before

        $driver = $this->staff('transport_operator');
        $this->post('/login', ['email' => $driver->email, 'password' => 'password'])->assertRedirect('/driver');
        $this->assertAuthenticatedAs($driver);
        $this->get('/')->assertRedirect('/driver');
        $this->get('/login')->assertRedirect('/driver'); // already signed in: the login page sends the driver to the same place
        auth()->logout();

        // Every other staff role still lands on the Job workbench; a client on the portal.
        $dispatcher = $this->staff('dispatcher');
        $this->post('/login', ['email' => $dispatcher->email, 'password' => 'password'])->assertRedirect('/jobs');
        $this->get('/')->assertRedirect('/jobs');
        auth()->logout();
        $this->actingAs($this->clientUser())->get('/')->assertRedirect('/portal');
    }

    public function test_job_summary_carries_cost_and_margin_only_for_the_office_roles(): void
    {
        $client = $this->client();
        $jobs = app(JobServiceContract::class);
        $job = $jobs->create($client->id, 'container');

        foreach (JobService::COST_ROLES as $role) {
            $this->be($this->staff($role));
            $this->assertArrayHasKey('margin_cents', $jobs->summarize($job['job_id']), $role);
        }
        foreach (['transport_operator', 'warehouse_operator', 'warehouse_supervisor'] as $role) {
            $this->be($this->staff($role));
            $summary = $jobs->summarize($job['job_id']);
            $this->assertArrayNotHasKey('margin_cents', $summary, $role);
            $this->assertArrayNotHasKey('estimated_cost_cents', $summary, $role);
            $this->assertArrayNotHasKey('actual_cost_cents', $summary, $role);
            $this->assertArrayHasKey('estimated_revenue_cents', $summary, $role);
        }
        // A system caller (console / consumer, nobody signed in) still gets the full summary.
        auth()->logout();
        $this->assertArrayHasKey('margin_cents', $jobs->summarize($job['job_id']));
    }

    public function test_job_page_hides_the_money_cards_and_panels_from_the_driver_and_the_floor(): void
    {
        $client = $this->client();
        $job = Job::query()->findOrFail(app(JobServiceContract::class)->create($client->id, 'container')['job_id']);
        $job->update(['estimated_revenue_cents' => 50000, 'estimated_cost_cents' => 23500]);
        $invoice = Invoice::query()->create(['invoice_no' => 'INV-202609-0077', 'client_id' => $client->id, 'invoice_type' => 'service', 'bill_to_name' => $client->name, 'status' => 'issued', 'total_cents' => 55000]);
        InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'job_id' => $job->id, 'charge_code' => 'WH-HANDLING', 'description' => 'Handling', 'qty' => 1, 'uom' => 'each', 'amount_cents' => 50000, 'tax_treatment' => 'gst_10', 'gst_cents' => 5000]);

        foreach (['transport_operator', 'warehouse_operator'] as $role) {
            $this->actingAs($this->staff($role))->get(route('platform.jobs.show', $job))->assertOk()
                ->assertSee($job->job_no)
                ->assertDontSee(__('platform.jobs.margin'))->assertDontSee(__('platform.jobs.estimated_cost'))->assertDontSee('235.00')
                ->assertDontSee(__('platform.jobs.panel_invoices'))->assertDontSee('INV-202609-0077')
                ->assertDontSee(__('platform.documents.upload_for', ['no' => $job->job_no]));
        }
        $this->actingAs($this->staff('finance'))->get(route('platform.jobs.show', $job))->assertOk()
            ->assertSee(__('platform.jobs.margin'))->assertSee('235.00')->assertSee(__('platform.jobs.panel_invoices'))->assertSee('INV-202609-0077');
    }

    public function test_only_the_office_and_the_warehouse_lead_open_jobs(): void
    {
        $client = $this->client();
        $payload = ['client_id' => $client->id, 'job_type' => 'loose', 'reference' => 'X'];

        foreach (['transport_operator', 'warehouse_operator', 'finance'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get('/jobs/create')->assertForbidden();
            $this->actingAs($user)->post('/jobs', $payload)->assertForbidden();
            $this->actingAs($user)->get('/jobs')->assertOk()->assertDontSee(route('platform.jobs.create')); // no 403 behind a button
        }
        $this->assertSame(0, Job::query()->count());

        foreach (['admin', 'customer_service', 'dispatcher', 'warehouse_supervisor'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get('/jobs/create')->assertOk();
            $this->actingAs($user)->get('/jobs')->assertOk()->assertSee(route('platform.jobs.create'));
            $this->actingAs($user)->post('/jobs', $payload)->assertRedirect();
        }
        $this->assertSame(4, Job::query()->count());
    }

    public function test_documents_centre_is_read_only_for_the_driver_the_floor_and_the_dispatcher(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $job = app(JobServiceContract::class)->create($client->id, 'loose');
        $upload = fn () => ['file' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf'), 'type' => 'pod', 'document_no' => $job['job_no'], 'client_visible' => 0];

        $this->actingAs($this->staff('customer_service'))->post('/admin/documents', $upload())->assertRedirect('/admin/documents');
        $document = Document::query()->firstOrFail();

        foreach (['transport_operator', 'warehouse_operator', 'dispatcher'] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get('/admin/documents')->assertOk()->assertSee('a.pdf')
                ->assertDontSee('enctype="multipart/form-data"', false)->assertDontSee(__('platform.documents.number_hint'))->assertDontSee(__('platform.documents.make_visible'));
            $this->actingAs($user)->get("/admin/documents/{$document->id}/download")->assertOk();
            $this->actingAs($user)->post('/admin/documents', $upload())->assertForbidden();
            $this->actingAs($user)->post("/admin/documents/{$document->id}/visibility", ['client_visible' => 1])->assertForbidden();
        }
        $this->assertFalse($document->fresh()->client_visible);
        $this->assertSame(1, Document::query()->count());

        foreach (['admin', 'finance', 'warehouse_supervisor'] as $role) {
            $this->actingAs($this->staff($role))->get('/admin/documents')->assertOk()->assertSee('enctype="multipart/form-data"', false)->assertSee(__('platform.documents.number_hint'));
        }
        $this->actingAs($this->staff('warehouse_supervisor'))->post("/admin/documents/{$document->id}/visibility", ['client_visible' => 1])->assertRedirect();
        $this->assertTrue($document->fresh()->client_visible);
    }
}
