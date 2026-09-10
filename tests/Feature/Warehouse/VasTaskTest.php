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

/** Tester feedback #6 (2026-09-10): 作业登记 is for billable VAS only; system-written tasks are read-only records. */
class VasTaskTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_hand_made_tasks_are_vas_only_and_can_bind_an_order_for_outbound_wrap(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'VAS1', 'cartons' => 4]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);

        $page = $this->actingAs($supervisor)->get(route('warehouse.tasks.create', ['order_id' => $order->id, 'task_type' => 'wrap']))->assertOk();
        $page->assertSee('value="wrap"', false)->assertSee('value="devanning"', false)->assertSee('value="labour"', false)
            ->assertDontSee('value="putaway"', false)->assertDontSee('value="move"', false)->assertDontSee('value="receiving"', false)->assertDontSee('value="pick"', false);

        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['asn_id' => $asn->id, 'task_type' => 'putaway'])->assertSessionHasErrors('task_type');
        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['task_type' => 'wrap'])->assertSessionHasErrors(['asn_id', 'order_id']);

        $this->actingAs($supervisor)->post(route('warehouse.tasks.store'), ['order_id' => $order->id, 'task_type' => 'wrap', 'notes' => 'stretch wrap 3 pallets'])->assertRedirect(route('warehouse.tasks.index'));
        $task = WarehouseTask::query()->where('task_type', 'wrap')->sole();
        $this->assertSame(['order', $order->id, $order->id, $order->job_id, $client->id], [$task->source_type, $task->source_id, $task->order_id, $task->job_id, $task->client_id]);

        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $task), ['billable_qty' => 3, 'billable_uom' => 'pallet'])->assertSessionHasNoErrors();
        $this->assertSame(['done', 3.0, 'pallet'], [$task->fresh()->status, (float) $task->fresh()->billable_qty, $task->fresh()->billable_uom]);
        $event = OutboxEvent::query()->where('event_name', 'task.completed')->where('payload->task_id', $task->id)->firstOrFail();
        $this->assertSame(['wrap', 'order', $order->id], [$event->payload['task_type'], $event->payload['source_type'], $event->payload['order_id']]); // → WH-WRAP-OUT-PLT rule

        // The order page offers the shortcut to warehouse roles only.
        $this->actingAs($supervisor)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.actions.register_vas'));
        $this->actingAs($this->staff('customer_service'))->get(route('orders.show', $order))->assertOk()->assertDontSee(__('orders.actions.register_vas'));
    }

    public function test_system_tasks_are_read_only_and_legacy_hand_made_records_can_be_cancelled(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        $tasks = app(TaskService::class);
        $base = ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'asn_id' => $asn->id, 'source_type' => 'asn', 'source_id' => $asn->id];
        $pick = $tasks->create('pick', $base + ['source_type' => 'fulfilment', 'source_id' => 1]);
        $legacy = $tasks->create('putaway', $base); // hand-made before 2026-09-10: never moved any stock

        $index = $this->actingAs($operator)->get(route('warehouse.tasks.index'))->assertOk();
        $index->assertSee(__('warehouse.tasks.system_record'))->assertDontSee(route('warehouse.tasks.complete', $pick))->assertDontSee(route('warehouse.tasks.cancel', $pick))
            ->assertSee(route('warehouse.tasks.cancel', $legacy))->assertDontSee(route('warehouse.tasks.complete', $legacy))->assertSee(__('warehouse.tasks.legacy_hint'));

        // The generic 完成 no longer bypasses pick confirmation, and system records cannot be cancelled by hand.
        $this->actingAs($operator)->post(route('warehouse.tasks.complete', $pick))->assertSessionHasErrors('task');
        $this->actingAs($operator)->post(route('warehouse.tasks.cancel', $pick))->assertSessionHasErrors('task');
        $this->assertSame('pending', $pick->fresh()->status);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'task.completed')->count());

        $this->actingAs($operator)->post(route('warehouse.tasks.complete', $legacy), ['billable_qty' => 1, 'billable_uom' => 'pallet'])->assertSessionHasErrors('task');
        $this->actingAs($operator)->post(route('warehouse.tasks.cancel', $legacy), ['cancel_reason' => 'created by mistake'])->assertSessionHasNoErrors();
        $this->assertSame(['cancelled', 'created by mistake'], [$legacy->fresh()->status, $legacy->fresh()->cancel_reason]);
        $this->actingAs($operator)->post(route('warehouse.tasks.cancel', $legacy))->assertSessionHasErrors('task'); // already cancelled
    }
}
