<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\Job;
use App\Support\Contracts\JobService;
use App\Support\Tenancy\ClientScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A27: jobs table, JobService, Job workbench skeleton. */
class JobWorkbenchTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_staff_create_a_job_from_the_form_and_see_it(): void
    {
        $admin = $this->staff();
        $client = $this->client();

        $response = $this->actingAs($admin)->post('/jobs', ['client_id' => $client->id, 'job_type' => 'container', 'reference' => 'COSU6508115030']);

        $job = Job::query()->firstOrFail();
        $response->assertRedirect(route('platform.jobs.show', $job));
        $this->assertMatchesRegularExpression('/^JOB-\d{8}-0001$/', $job->job_no);
        $this->assertSame($admin->id, $job->created_by);

        $this->actingAs($admin)->get('/jobs')->assertOk()->assertSee($job->job_no);
        $this->actingAs($admin)->get(route('platform.jobs.show', $job))->assertOk()
            ->assertSee('COSU6508115030')
            ->assertSee(__('platform.jobs.margin'));
    }

    public function test_job_numbers_increment_within_the_day(): void
    {
        $client = $this->client();
        $jobs = app(JobService::class);

        $this->assertStringEndsWith('-0001', $jobs->create($client->id, 'loose')['job_no']);
        $this->assertStringEndsWith('-0002', $jobs->create($client->id, 'transport_only')['job_no']);
    }

    public function test_unknown_job_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(JobService::class)->create($this->client()->id, 'bogus');
    }

    public function test_summary_hides_cost_and_margin_from_client_users(): void
    {
        $client = $this->client();
        $jobs = app(JobService::class);
        $job = $jobs->create($client->id, 'container');

        $staffView = $jobs->summarize($job['job_id']);
        $this->assertArrayHasKey('margin_cents', $staffView);
        $this->assertTrue($staffView['margin_is_estimate']);
        $this->assertSame(['open', 'unbilled', 'estimated'], [$staffView['operational_status'], $staffView['revenue_status'], $staffView['cost_status']]);

        ClientScope::set($client->id);
        $clientView = $jobs->summarize($job['job_id']);
        $this->assertArrayNotHasKey('margin_cents', $clientView);
        $this->assertArrayNotHasKey('estimated_cost_cents', $clientView);
        $this->assertArrayNotHasKey('actual_cost_cents', $clientView);
        $this->assertArrayHasKey('estimated_revenue_cents', $clientView);
    }

    public function test_client_scope_hides_other_clients_jobs(): void
    {
        $a = $this->client();
        $b = $this->client();
        $jobs = app(JobService::class);
        $jobForB = $jobs->create($b->id, 'container');

        ClientScope::set($a->id);
        $this->expectException(ModelNotFoundException::class);
        $jobs->summarize($jobForB['job_id']);
    }

    public function test_client_user_cannot_open_a_job_for_another_client(): void
    {
        $a = $this->client();
        $b = $this->client();

        ClientScope::set($a->id);
        $this->expectException(ModelNotFoundException::class);
        app(JobService::class)->create($b->id, 'container');
    }

    /** §2.5 #6 / §7 step 9: the Job page shows the ASN, orders, shipments, stock and documents that hang off it. */
    public function test_job_page_lists_everything_that_hangs_off_the_job(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'JOB1', 'cartons' => 4]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $shipmentNo = DB::table('shipments')->where('order_id', $order->id)->value('shipment_no');

        $page = $this->actingAs($this->staff('customer_service'))->get(route('platform.jobs.show', $asn->job_id))->assertOk();
        $page->assertSee($asn->asn_no)->assertSee($order->order_no)->assertSee((string) $shipmentNo)->assertSee(__('platform.jobs.panel_stock'))->assertDontSee(__('platform.jobs.panel_pending', ['module' => 'Orders', 'checkpoint' => 'M3']));
    }
}
