<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\WarehouseTaskLine;
use App\Modules\Warehouse\Models\Wave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #152: the wave page confirms every ticked open line at 应拣数 in one post; short picks stay per row; 待释放 has 全选 / 全不选 buttons. */
class PickAllTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_all_open_lines_of_a_wave_are_confirmed_in_one_post_and_a_short_line_stays_for_its_own_row(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'PA1', 'cartons' => 8, 'weight_kg' => 40], ['mark' => 'PA2', 'cartons' => 6, 'weight_kg' => 30]]);
        $orderA = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $orderB = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[1]->id, 'qty' => 4]]);

        // 待释放: the visible 全选 / 全不选 buttons next to the header tick.
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()
            ->assertSee('id="release-select-all"', false)->assertSee(__('warehouse.outbound.select_all_btn'))->assertSee(__('warehouse.outbound.select_none_btn'));
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$orderA->id, $orderB->id]])->assertRedirect();
        $wave = Wave::query()->firstOrFail();
        $lines = WarehouseTaskLine::query()->whereIn('task_id', $wave->tasks()->pluck('id'))->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $lines->count());

        // The wave page: a checkbox per open line bound to the 全部确认 form, default ticked, the count on the button; no raw lang key.
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()
            ->assertSee('id="pick-all"', false)->assertSee('name="line_ids[]" value="'.$lines->first()->id.'" form="pick-all"', false)
            ->assertSee(__('warehouse.outbound.pick_all.title'))->assertSee(__('warehouse.outbound.pick_all.submit', ['count' => $lines->count()]));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.outbound\./', $page->getContent());

        // One line is short-picked on its own row first (reason required, as before); it is then no longer open.
        $shortLine = $lines->first();
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $shortLine), ['picked_qty' => $shortLine->required_qty - 1, 'short_reason' => 'damaged'])->assertRedirect()->assertSessionHasNoErrors();

        // Nothing ticked → Chinese refusal; the rest confirmed in one post at 应拣数 (the short line's id is ignored, already confirmed).
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.pick_all', $wave), [])->assertSessionHasErrors('line_ids');
        $openIds = $lines->pluck('id')->all();
        $this->actingAs($operator)->from(route('warehouse.outbound.waves.show', $wave))->post(route('warehouse.outbound.waves.pick_all', $wave), ['line_ids' => $openIds])
            ->assertRedirect(route('warehouse.outbound.waves.show', $wave))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('warehouse.outbound.pick_all.done', ['count' => $lines->count() - 1]));
        foreach ($lines as $line) {
            $fresh = $line->fresh();
            $this->assertNotNull($fresh->confirmed_at);
            $this->assertSame($line->id === $shortLine->id ? $line->required_qty - 1 : $line->required_qty, (int) $fresh->completed_qty);
        }
        $this->assertSame(['done'], WarehouseTask::query()->whereIn('id', $wave->tasks()->pluck('id'))->pluck('status')->unique()->all(), 'every pick task of the wave is done');
        $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk()->assertDontSee('id="pick-all"', false);

        // A second post finds nothing open; staff outside the warehouse roles are refused.
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.pick_all', $wave), ['line_ids' => $openIds])->assertSessionHasErrors('line_ids');
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.outbound.waves.pick_all', $wave), ['line_ids' => $openIds])->assertForbidden();
    }
}
