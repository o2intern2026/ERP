<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-07 (CR #141): a VAS record could be completed with an empty quantity — done, no charge, no warning. The billable
 * figure is now required per type (Chinese refusal), and 现在完成 on the create page creates + completes in one step.
 */
class VasCompletionQuantityTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_completion_needs_the_billable_figure_of_its_type(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $tasks = app(TaskService::class);
        $base = ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'asn_id' => $asn->id, 'source_type' => 'asn', 'source_id' => $asn->id];
        $wrap = $tasks->create('wrap', $base);
        $labour = $tasks->create('labour', $base);
        $scanning = $tasks->create('scanning', $base);
        $waste = $tasks->create('waste', $base);

        // The list's inputs are required; an empty 完成 is refused in Chinese and nothing is billed.
        $this->actingAs($supervisor)->get(route('warehouse.tasks.index'))->assertOk()->assertSee('name="billable_qty"', false)->assertSee('min="0.001"', false);
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $wrap))->assertSessionHasErrors(['billable_qty' => __('warehouse.tasks.quantity_required.qty', ['type' => __('warehouse.task_types.wrap')])]);
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $wrap), ['billable_qty' => 0, 'billable_uom' => 'pallet'])->assertSessionHasErrors('billable_qty');
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $labour), ['hours_business' => 0, 'hours_after_hours' => 0])->assertSessionHasErrors(['billable_qty' => __('warehouse.tasks.quantity_required.hours', ['type' => __('warehouse.task_types.labour')])]);
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $scanning), ['scan_count' => ''])->assertSessionHasErrors(['billable_qty' => __('warehouse.tasks.quantity_required.scans')]);
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $waste))->assertSessionHasErrors('billable_qty');
        $this->assertSame(4, WarehouseTask::query()->where('status', 'pending')->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'task.completed')->count());
        $this->assertMatchesRegularExpression('/\p{Han}/u', __('warehouse.tasks.quantity_required.qty', ['type' => 'x']));

        // With the figure: done, billed.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $wrap), ['billable_qty' => 3, 'billable_uom' => 'pallet'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $labour), ['hours_after_hours' => 1.5])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $scanning), ['serials' => "SN-1\nSN-2"])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $waste), ['billable_qty' => 0.5, 'billable_uom' => 'cbm'])->assertSessionHasNoErrors();
        $this->assertSame(['done', 3.0, 'pallet'], [$wrap->fresh()->status, (float) $wrap->fresh()->billable_qty, $wrap->fresh()->billable_uom]);
        $this->assertSame(['done', 1.5], [$labour->fresh()->status, (float) $labour->fresh()->hours_after_hours]);
        $this->assertSame(['done', 2.0, 'scan'], [$scanning->fresh()->status, (float) $scanning->fresh()->billable_qty, $scanning->fresh()->billable_uom]);
        $this->assertSame(4, OutboxEvent::query()->where('event_name', 'task.completed')->count());
    }

    public function test_create_page_completes_in_one_step_when_asked_and_refuses_an_empty_quantity(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'NOW1', 'cartons' => 4]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);

        $this->actingAs($supervisor)->get(route('warehouse.tasks.create', ['order_id' => $order->id, 'task_type' => 'wrap']))->assertOk()
            ->assertSee(__('warehouse.tasks.complete_now'))->assertSee('id="completion-fields"', false)->assertSee('data-types="labour vas_other"', false);

        // 现在完成 without the quantity: refused, nothing created.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['order_id' => $order->id, 'task_type' => 'wrap', 'complete_now' => 1])
            ->assertSessionHasErrors(['billable_qty' => __('warehouse.tasks.quantity_required.qty', ['type' => __('warehouse.task_types.wrap')])]);
        $this->assertSame(0, WarehouseTask::query()->where('task_type', 'wrap')->count());

        // With it: created and done in one step, the billing event out, the flash says so.
        $response = $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['order_id' => $order->id, 'task_type' => 'wrap', 'complete_now' => 1, 'billable_qty' => 2, 'billable_uom' => 'pallet', 'notes' => 'wrapped at the dock'])
            ->assertSessionHasNoErrors()->assertRedirect(route('warehouse.tasks.index'));
        $task = WarehouseTask::query()->where('task_type', 'wrap')->sole();
        $this->assertSame(['done', 2.0, 'pallet', 'wrapped at the dock'], [$task->status, (float) $task->billable_qty, $task->billable_uom, $task->notes]);
        $this->assertSame((int) $supervisor->id, (int) $task->completed_by);
        $response->assertSessionHas('status', __('warehouse.tasks.created_completed', ['task_no' => $task->task_no]));
        $event = OutboxEvent::query()->where('event_name', 'task.completed')->where('payload->task_id', $task->id)->firstOrFail();
        $this->assertSame(['wrap', 2.0], [$event->payload['task_type'], (float) $event->payload['billable_qty']]);

        // Labour by hours in one step; without 现在完成 the record is created pending as before.
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'task_type' => 'labour', 'complete_now' => 1, 'hours_business' => 2])->assertSessionHasNoErrors();
        $this->assertSame('done', WarehouseTask::query()->where('task_type', 'labour')->sole()->status);
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'task_type' => 'waste'])->assertSessionHasNoErrors();
        $this->assertSame('pending', WarehouseTask::query()->where('task_type', 'waste')->sole()->status);
    }
}
