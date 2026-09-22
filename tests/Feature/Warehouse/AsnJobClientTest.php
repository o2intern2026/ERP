<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\Job;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\WarehouseContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-13 (CR #141): 手工新建预报单 listed every client's Jobs and never checked that the Job belonged to the chosen
 * client. The dropdown now carries data-client (filtered on the page) and the server refuses a Job of another client.
 */
class AsnJobClientTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_job_must_belong_to_the_chosen_client_and_the_dropdown_is_filtered_per_client(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $other = $this->warehouse('SYD');
        $a = $this->client(['name' => 'Alpha']);
        $b = $this->client(['name' => 'Bravo']);
        $asnA = app(AsnService::class)->create(['client_id' => $a->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $asnB = app(AsnService::class)->create(['client_id' => $b->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $jobA = Job::query()->findOrFail($asnA->job_id);
        $jobB = Job::query()->findOrFail($asnB->job_id);

        // The page: every Job option names its client for the filter script; the session warehouse is preselected.
        WarehouseContext::set($other->id);
        $page = $this->actingAs($cs)->withSession([])->get(route('warehouse.asns.create'))->assertOk();
        $page->assertSee('data-client="'.$a->id.'"', false)->assertSee('data-client="'.$b->id.'"', false)->assertSee('id="job_id"', false)->assertSee('syncJobs', false);
        $page->assertSee('value="'.$other->id.'" selected', false);

        // Client A with client B's Job → refused in Chinese, nothing created.
        $form = ['client_id' => $a->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'unplanned' => 0, 'inbound_transport' => 'client_delivers'];
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $form + ['job_id' => $jobB->id])
            ->assertSessionHasErrors(['job_id' => __('warehouse.asns.job_not_of_client')]);
        $this->assertSame(2, Asn::query()->withoutGlobalScopes()->count());

        // Own Job → the ASN joins it; no Job → a new one, as before.
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $form + ['job_id' => $jobA->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($jobA->id, Asn::query()->withoutGlobalScopes()->orderByDesc('id')->first()->job_id);
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $form)->assertSessionHasNoErrors();
        $this->assertNotContains(Asn::query()->withoutGlobalScopes()->orderByDesc('id')->first()->job_id, [$jobA->id, $jobB->id]);
    }
}
