<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Billing\Models\Charge;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-08: 登记拆柜 from the ASN page saved a devanning task with container_id NULL (the 柜号 select started at "—"), and
 * every WH-DEVAN-* rule matches on the container's size × unpack mode — so the default path produced no devanning fee and no exception.
 * Now the ASN's only unlinked container is preselected, the ASN page offers 登记拆柜 per container row (container_id in the link), and a
 * devanning task on an ASN that has containers refuses to save without one.
 */
class DevanningContainerTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_devanning_registered_from_the_asn_page_carries_its_container_and_bills_the_unpack_fee(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'DEVAN40', 'size' => '40', 'unpack_mode' => 'pallet', 'gross_weight_kg' => 15000]]]);
        $container = $asn->containers()->firstOrFail();
        $link = route('warehouse.tasks.create', ['asn_id' => $asn->id, 'container_id' => $container->id, 'task_type' => 'devanning']);

        // The ASN page: a 登记拆柜 link on the container row that passes container_id.
        $this->actingAs($supervisor)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.register_devanning'))->assertSee($link); // escaped: the href carries &amp;

        // The generic 作业登记 link (asn_id only): the only unlinked container is preselected server side.
        $this->actingAs($supervisor)->get(route('warehouse.tasks.create', ['asn_id' => $asn->id]))->assertOk()
            ->assertSee('<option value="'.$container->id.'" selected>DEVAN40</option>', false);
        $this->actingAs($supervisor)->get($link)->assertOk()->assertSee('<option value="'.$container->id.'" selected>DEVAN40</option>', false);

        // Saving without the container is refused in Chinese and creates nothing.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'task_type' => 'devanning'])
            ->assertSessionHasErrors(['container_id' => __('warehouse.tasks.container_required')]);
        $this->assertSame(0, WarehouseTask::query()->withoutGlobalScopes()->count());
        // A wrap task on the same ASN needs no container.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'task_type' => 'wrap'])->assertRedirect(route('warehouse.tasks.index'))->assertSessionHasNoErrors();

        // With the container: the task knows its box, and completing it yields the 40' palletised devanning fee.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'container_id' => $container->id, 'task_type' => 'devanning'])->assertRedirect(route('warehouse.tasks.index'))->assertSessionHasNoErrors();
        $task = WarehouseTask::query()->withoutGlobalScopes()->where('task_type', 'devanning')->sole();
        $this->assertSame([$container->id, 'container', $container->id, $asn->id], [$task->container_id, $task->source_type, $task->source_id, $task->asn_id]);
        $this->actingAs($supervisor)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee($task->task_no)->assertDontSee($link);

        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $task))->assertRedirect()->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $charge = Charge::query()->with('chargeCode')->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-DEVAN-40-PLT'))->sole();
        $this->assertSame([28000, "task:{$task->id}", $asn->job_id], [$charge->amount_cents, $charge->source_activity_id, $charge->job_id]);
    }

    public function test_an_asn_without_containers_still_registers_devanning_without_one(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);

        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'task_type' => 'devanning'])->assertRedirect(route('warehouse.tasks.index'))->assertSessionHasNoErrors();
        $task = WarehouseTask::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['asn', $asn->id, null], [$task->source_type, $task->source_id, $task->container_id]);
    }
}
