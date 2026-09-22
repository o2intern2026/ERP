<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 OUTBOUND-12 (CR #141): un-ticking every 待释放 row released ALL allocated batches of the warehouse, and a wave cannot be undone.
 * The release now needs at least one order (server + page), the board has 全选 with a live count, 箱数 per row and a due badge.
 */
class WaveSelectionTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_release_requires_a_selection_and_the_board_shows_size_and_count(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'WS1', 'cartons' => 40, 'weight_kg' => 200]]);
        $due = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 7]], ['requested_date' => today()->toDateString()]);
        $later = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]], ['requested_date' => today()->addDays(3)->toDateString()]);

        $page = $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk();
        $page->assertSee('id="release-all"', false)->assertSee(__('warehouse.outbound.release_count', ['count' => 2]))->assertSee(__('warehouse.outbound.cartons'))
            ->assertSee('data-client="'.$client->id.'"', false)->assertSee('data-date="'.today()->toDateString().'"', false)
            ->assertSee(__('warehouse.outbound.due_badge'))->assertSee('<td class="num">7</td>', false)->assertSee('<td class="num">3</td>', false)
            ->assertSee('name="client_id"', false)->assertSee('name="requested_date"', false);
        $this->assertSame(1, substr_count($page->getContent(), __('warehouse.outbound.due_badge')), 'only the order due today carries the badge');

        // Nothing ticked → refused in Chinese; no wave, no task — the audit's "release everything" path is closed.
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id])
            ->assertSessionHasErrors(['order_ids' => __('warehouse.outbound.errors.select_orders')]);
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => []])
            ->assertSessionHasErrors(['order_ids' => __('warehouse.outbound.errors.select_orders')]);
        $this->assertSame(0, Wave::query()->count());
        $this->assertSame(0, WarehouseTask::query()->where('task_type', 'pick')->count());

        // One ticked → only that order is released; the other stays 待释放 with the count on the button.
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$due->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$due->id], WarehouseTask::query()->where('task_type', 'pick')->pluck('order_id')->all());
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()->assertSee(__('warehouse.outbound.release_count', ['count' => 1]))->assertSee($later->order_no);
    }
}
