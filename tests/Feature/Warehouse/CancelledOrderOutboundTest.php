<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Orders\Models\Order;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\StockReservation;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Exceptions\RuleViolation;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/**
 * Audit 2026-09-22 OUTBOUND-02: nothing on the outbound board looked at orders.operational_status, so a cancelled order stayed in 待释放,
 * its pick lines stayed confirmable, a packed one kept a live 发运交接 button, and its pick task (a system task) could never be closed.
 * Now the board and releaseWave skip cancelled orders; confirmPick / pack / dispatch refuse them in Chinese; the wave page shows
 * 订单已取消 — 已拣货物请放回原库位 with a supervisor-only 关闭任务; and order.cancelled cancels pick tasks nobody has started.
 */
class CancelledOrderOutboundTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function cancel(Order $order): void
    {
        // What Orders' performCancel() leaves behind for Warehouse to read: the order is cancelled, the fulfilment row stays `allocated`.
        DB::table('orders')->where('id', $order->id)->update(['operational_status' => 'cancelled']);
    }

    public function test_a_cancelled_order_is_neither_offered_for_release_nor_released(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => [$asnLine]] = $this->stockedAsn($client, $warehouse, [['mark' => 'CX', 'cartons' => 20]]);
        $cancelled = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => 5]]);
        $live = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => 3]]);
        $this->cancel($cancelled);

        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee($live->order_no)->assertDontSee($cancelled->order_no);
        try {
            app(OutboundService::class)->releaseWave($warehouse->id, ['order_ids' => [$cancelled->id]], $operator->id);
            $this->fail('a cancelled order must not be released into a wave');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('warehouse.outbound.errors.no_candidates'), $e->getMessage());
        }
        ['tasks' => $tasks] = app(OutboundService::class)->releaseWave($warehouse->id, [], $operator->id);
        $this->assertSame([$live->id], $tasks->pluck('order_id')->all());
    }

    public function test_pick_pack_and_dispatch_refuse_a_cancelled_order_in_chinese(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => [$asnLine]] = $this->stockedAsn($client, $warehouse, [['mark' => 'CX', 'cartons' => 30, 'weight_kg' => 300]]);
        $outbound = app(OutboundService::class);
        $orders = [];
        foreach ([2, 3, 4] as $qty) {
            $orders[$qty] = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => $qty]]);
        }
        ['tasks' => $tasks] = $outbound->releaseWave($warehouse->id, [], $operator->id);
        $taskOf = fn (Order $o) => $tasks->firstWhere('order_id', $o->id);
        $fulfilmentOf = fn (Order $o) => (int) DB::table('fulfilments')->where('order_id', $o->id)->value('id');

        // Pick: cancelled while the task was open.
        $picking = $orders[2];
        $this->cancel($picking);
        $line = $taskOf($picking)->lines->first();
        try {
            $outbound->confirmPick($line, 2, $operator->id);
            $this->fail('a cancelled order is not picked');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.outbound.errors.order_cancelled', $e->langKey());
        }
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $line), ['picked_qty' => 2])
            ->assertSessionHasErrors(['picked_qty' => __('warehouse.outbound.errors.order_cancelled', ['order' => $picking->order_no])]);
        $this->assertNull($line->fresh()->confirmed_at);
        $this->assertDatabaseMissing('stock_ledger', ['movement_type' => 'pick', 'source_id' => $taskOf($picking)->id]);

        // Pack: cancelled after the picks were confirmed.
        $packing = $orders[3];
        foreach ($taskOf($packing)->lines as $l) {
            $outbound->confirmPick($l, $l->required_qty, $operator->id);
        }
        $this->cancel($packing);
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilmentOf($packing)), ['packages' => [['package_type' => 'carton', 'weight_kg' => '5']]])
            ->assertSessionHasErrors(['packages' => __('warehouse.outbound.errors.order_cancelled', ['order' => $packing->order_no])]);
        $this->assertDatabaseMissing('packages', ['fulfilment_id' => $fulfilmentOf($packing)]);
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee(__('warehouse.outbound.order_cancelled_badge'))
            ->assertDontSee(route('warehouse.outbound.pack.form', $fulfilmentOf($packing)));

        // Dispatch: cancelled after packing — the board shows the badge instead of the handover form, and the POST is refused.
        $dispatching = $orders[4];
        foreach ($taskOf($dispatching)->lines as $l) {
            $outbound->confirmPick($l, $l->required_qty, $operator->id);
        }
        $outbound->pack($fulfilmentOf($dispatching), [['package_type' => 'carton', 'weight_kg' => 5.0]], $operator->id);
        $this->cancel($dispatching);
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertDontSee(route('warehouse.outbound.dispatch', $fulfilmentOf($dispatching)));
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilmentOf($dispatching)), ['pallet_count' => 0, 'handed_to' => 'carrier'])
            ->assertSessionHasErrors(['pallet_count' => __('warehouse.outbound.errors.order_cancelled', ['order' => $dispatching->order_no])]);
        $this->assertDatabaseMissing('outbound_dispatches', ['fulfilment_id' => $fulfilmentOf($dispatching)]);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'outbound.dispatched', 'job_id' => $asn->job_id]);
    }

    public function test_the_supervisor_closes_a_started_pick_task_of_a_cancelled_order_and_the_wave_completes(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => [$a, $b]] = $this->stockedAsn($client, $warehouse, [['mark' => 'CA', 'cartons' => 10], ['mark' => 'CB', 'cartons' => 10, 'location_type' => 'pickface']]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $a->id, 'qty' => 4], ['asn_line_id' => $b->id, 'qty' => 6]]);
        $outbound = app(OutboundService::class);
        ['wave' => $wave, 'tasks' => $tasks] = $outbound->releaseWave($warehouse->id, [], $operator->id);
        $task = $tasks->first();
        [$first, $second] = $task->lines;
        $outbound->confirmPick($first, $first->required_qty, $operator->id); // started: one line picked
        $this->assertSame('in_progress', $task->fresh()->status);

        // Not cancelled yet: 关闭任务 is refused.
        try {
            $outbound->closeCancelledTask($task->fresh(), $supervisor->id);
            $this->fail('a live order\'s task is not closed');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.outbound.errors.order_not_cancelled', $e->langKey());
        }

        $this->cancel($order);
        $close = route('warehouse.outbound.tasks.close', $task);

        // The operator sees the instruction but no button, and cannot post it.
        $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()
            ->assertSee(__('warehouse.outbound.cancelled_card'))->assertSee(__('warehouse.outbound.order_cancelled_badge'))
            ->assertDontSee($close, false)->assertDontSee(route('warehouse.outbound.pick', $second), false);
        $this->actingAs($operator)->post($close)->assertForbidden();
        $this->assertSame('in_progress', $task->fresh()->status);

        // The supervisor closes it: task cancelled, the unpicked line's reservation released (stock.released), wave completed.
        $this->actingAs($supervisor)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()->assertSee($close, false)->assertSee(__('warehouse.outbound.close_task'));
        $this->actingAs($supervisor)->post($close)->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.outbound.task_closed', ['task_no' => $task->task_no]));
        $task = $task->fresh();
        $this->assertSame(['cancelled', 'order_cancelled', $supervisor->id], [$task->status, $task->cancel_reason, $task->completed_by]);
        $this->assertSame('completed', $wave->fresh()->status);
        $this->assertSame(0, StockReservation::query()->where('order_id', $order->id)->where('status', 'active')->count());
        $released = OutboxEvent::query()->where('event_name', 'stock.released')->where('job_id', $asn->job_id)->firstOrFail();
        $this->assertSame([$order->id, 6, 'order_cancelled'], [$released->payload['order_id'], $released->payload['qty'], $released->payload['reason']]);
        $this->assertSame(0, $second->stockUnit->fresh()->qty_reserved);
        $this->artisan('stock:reconcile')->assertSuccessful();

        // Closed once; the card now shows the cancelled task and nothing to confirm.
        $this->actingAs($supervisor)->post($close)->assertSessionHasErrors(['close' => __('warehouse.tasks.not_pending', ['task_no' => $task->task_no])]);
        $this->actingAs($supervisor)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()->assertDontSee($close, false)->assertSee(__('warehouse.task_statuses.cancelled'));
        $this->actingAs($this->staff('admin'))->get(route('warehouse.outbound.index'))->assertOk()->assertDontSee($task->task_no); // no longer 拣货中
    }

    public function test_order_cancelled_cancels_the_unstarted_pick_task_and_completes_its_wave(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => [$asnLine]] = $this->stockedAsn($client, $warehouse, [['mark' => 'CE', 'cartons' => 20]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => 7]]);
        ['wave' => $wave, 'tasks' => $tasks] = app(OutboundService::class)->releaseWave($warehouse->id, [], $operator->id);
        $task = $tasks->first();
        $this->assertSame('pending', $task->status);

        $this->cancel($order);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([
            'order_id' => $order->id, 'order_no' => $order->order_no, 'job_id' => $order->job_id, 'client_id' => $client->id,
            'previous_status' => 'picking', 'cancelled_by' => 1, 'reason' => 'customer', 'cancelled_at' => now()->toIso8601String(),
        ], 'order.cancelled', $order->job_id, $client->id)));
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(['cancelled', 'order_cancelled'], [$task->fresh()->status, $task->fresh()->cancel_reason]);
        $this->assertSame('completed', $wave->fresh()->status);
        $this->assertSame(0, StockReservation::query()->where('order_id', $order->id)->where('status', 'active')->count());
        $this->assertSame(7, OutboxEvent::query()->where('event_name', 'stock.released')->where('job_id', $asn->job_id)->firstOrFail()->payload['qty']);
        $this->assertSame(['qty_on_hand' => 20, 'qty_reserved' => 0], array_intersect_key($asnLine->stockUnits()->first()->fresh()->only(['qty_on_hand', 'qty_reserved']), ['qty_on_hand' => 1, 'qty_reserved' => 1]));
        $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()->assertSee(__('warehouse.task_statuses.cancelled'))->assertDontSee(route('warehouse.outbound.tasks.close', $task), false);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }
}
