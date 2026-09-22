<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Warehouse\Models\Stocktake;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\StocktakeService;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CR #142 decision 1 (lead 2026-09-22, follows audit OUTBOUND-08 / CR #141): a short pick with reason 找不到 does NOT return the shortfall
 * to available stock. The cartons are frozen on the unit (`stock_units.qty_frozen`, excluded from every 可用 figure — unit page, stock list,
 * StockService::onHand, FIFO reservation, client portal), the unit is appended to the warehouse's open 差异盘点 (one per warehouse, one line
 * per unit) and the pick_short exception points at that stocktake. Counting resolves it: counted ≥ expected releases the frozen cartons at
 * once; counted < expected adjusts on close (the usual stocktake `adjust`) and clears the frozen quantity. 缺货 / 破损 / 其他 keep today's
 * release-to-available behaviour.
 */
class PickShortNotFoundTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** Two units of 10 cartons each on one ASN line; one order for 6 out of each; the wave released. Returns [client, warehouse, asn, line, units, order, pick lines by unit]. */
    private function shortPickScene(): array
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines, 'units' => $units] = $this->stockedAsn($client, $warehouse, [['mark' => 'NF1', 'cartons' => 20, 'weight_kg' => 100, 'units' => [['unit_type' => 'carton', 'carton_qty' => 10], ['unit_type' => 'carton', 'carton_qty' => 10]]]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 12]]); // 10 from the first unit, 2 from the second (FIFO)
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$order->id]])->assertRedirect();
        $task = WarehouseTask::query()->where('task_type', 'pick')->where('order_id', $order->id)->firstOrFail();
        $pickLines = $task->lines()->get()->keyBy('stock_unit_id');

        return [$client, $warehouse, $asn, $asnLines[0], $units, $order, $pickLines, $operator];
    }

    public function test_not_found_freezes_the_shortfall_out_of_available_and_opens_one_discrepancy_stocktake_line_the_exception_points_at(): void
    {
        [$client, $warehouse, , $asnLine, $units, $order, $pickLines, $operator] = $this->shortPickScene();
        [$first, $second] = $units;
        $supervisor = $this->staff('warehouse_supervisor');

        // 7 of 10 picked from the first unit, 3 找不到.
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $pickLines[$first->id]), ['picked_qty' => 7, 'short_reason' => 'not_found', 'short_note' => '库位空了'])->assertRedirect()->assertSessionHasNoErrors();
        $first->refresh();
        $this->assertSame([3, 0, 3, 0], [$first->qty_on_hand, $first->qty_reserved, $first->qty_frozen, $first->availableQty()]);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $first->id, 'movement_type' => 'release', 'qty' => -3]); // the reservation is consumed as before
        $this->assertDatabaseMissing('stock_ledger', ['stock_unit_id' => $first->id, 'movement_type' => 'adjust']);            // nothing adjusted yet — the count decides

        // One 差异盘点 for the warehouse, one line for the unit, expected = the unit's on hand (frozen included), still counting.
        $stocktake = Stocktake::query()->where('kind', Stocktake::KIND_DISCREPANCY)->sole();
        $this->assertSame([$warehouse->id, 'counting', null, null], [$stocktake->warehouse_id, $stocktake->status, $stocktake->client_id, $stocktake->location_id]);
        $stocktakeLine = $stocktake->lines()->sole();
        $this->assertSame([$first->id, 3, null], [$stocktakeLine->stock_unit_id, $stocktakeLine->expected_qty, $stocktakeLine->counted_qty]);

        // The pick_short exception carries the stocktake as its source and names it; the exception centre links there for the supervisor.
        $exception = ExceptionRecord::query()->where('type', 'pick_short')->where('order_id', $order->id)->sole();
        $this->assertSame(['stocktake', $stocktake->id], [$exception->source_type, $exception->source_id]);
        $this->assertStringContainsString($stocktake->stocktake_no, $exception->message);
        $this->assertStringContainsString(__('warehouse.outbound.pick_short_frozen_suffix', ['short' => 3, 'stocktake' => $stocktake->stocktake_no]), $exception->message);
        $this->actingAs($supervisor)->get('/admin/exceptions')->assertOk()->assertSee(route('warehouse.stocktakes.show', $stocktake));

        // Every 可用 figure excludes the frozen cartons: the service, the unit page, the stock list, the client portal; FIFO reservation skips them.
        $this->assertSame(['qty_on_hand' => 13, 'qty_reserved' => 2, 'qty_available' => 8], app(StockService::class)->onHand($client->id, $asnLine->id)); // 3 + 10 on hand, 2 reserved on the second unit, 3 frozen
        $this->actingAs($supervisor)->get(route('warehouse.stock.show', $first))->assertOk()->assertSee(__('warehouse.stock.frozen'))->assertSee(__('warehouse.stock.frozen_hint', ['qty' => 3]));
        $this->actingAs($supervisor)->get(route('warehouse.index', ['warehouse_id' => $warehouse->id, 'available_only' => 1]))->assertOk()->assertSee($second->label_code)->assertDontSee($first->label_code);
        $this->actingAs($this->clientUser($client))->get(route('portal.stock.index'))->assertOk()->assertSee('<strong>8</strong>', false);
        $reserved = app(StockService::class)->reserve($client->id, 999001, [['order_line_id' => 999001, 'asn_line_id' => $asnLine->id, 'qty' => 9]]);
        $this->assertSame([8, 1], [$reserved[0]['reserved_qty'], $reserved[0]['shortfall_qty']]);
        app(StockService::class)->release(999001);

        // The stocktake page shows what it is and the frozen figure; a second 找不到 in the same warehouse joins the same open stocktake.
        $this->actingAs($supervisor)->get(route('warehouse.stocktakes.show', $stocktake))->assertOk()
            ->assertSee(__('warehouse.stocktakes.kinds.discrepancy'))->assertSee(__('warehouse.stocktakes.discrepancy_hint'))->assertSee(__('warehouse.stocktakes.frozen_badge', ['qty' => 3]));
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $pickLines[$second->id]), ['picked_qty' => 1, 'short_reason' => 'not_found'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Stocktake::query()->where('kind', Stocktake::KIND_DISCREPANCY)->count());
        $this->assertSame([$first->id, $second->id], $stocktake->lines()->orderBy('id')->pluck('stock_unit_id')->all());
        $this->assertSame(1, $second->fresh()->qty_frozen);
        $this->actingAs($supervisor)->get(route('warehouse.stocktakes.index'))->assertOk()->assertSee(__('warehouse.stocktakes.kinds.discrepancy'));
    }

    public function test_counting_the_discrepancy_line_releases_found_cartons_at_once_and_adjusts_the_rest_on_close(): void
    {
        [$client, , , $asnLine, $units, , $pickLines, $operator] = $this->shortPickScene();
        [$first, $second] = $units;
        $supervisor = $this->staff('warehouse_supervisor');
        $service = app(StocktakeService::class);

        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $pickLines[$first->id]), ['picked_qty' => 7, 'short_reason' => 'not_found'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $pickLines[$second->id]), ['picked_qty' => 0, 'short_reason' => 'not_found'])->assertRedirect()->assertSessionHasNoErrors();
        $stocktake = Stocktake::query()->where('kind', Stocktake::KIND_DISCREPANCY)->sole();
        $this->assertSame([3, 3], [$first->fresh()->qty_on_hand, $first->fresh()->qty_frozen]);
        $this->assertSame([10, 2], [$second->fresh()->qty_on_hand, $second->fresh()->qty_frozen]);
        $this->assertSame(['qty_on_hand' => 13, 'qty_reserved' => 0, 'qty_available' => 8], app(StockService::class)->onHand($client->id, $asnLine->id));

        // First unit: all 3 are there after all → counted ≥ expected releases the frozen cartons immediately (no close needed).
        $lineFirst = $stocktake->lines()->where('stock_unit_id', $first->id)->sole();
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.count', [$stocktake, $lineFirst]), ['counted_qty' => 3])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([3, 0, 3], [$first->fresh()->qty_on_hand, $first->fresh()->qty_frozen, $first->fresh()->availableQty()]);
        $this->assertSame(['qty_on_hand' => 13, 'qty_reserved' => 0, 'qty_available' => 11], app(StockService::class)->onHand($client->id, $asnLine->id));

        // Second unit: only 9 of 10 are really there → frozen stays until the close; the close adjusts −1 through the ledger and clears the freeze.
        $lineSecond = $stocktake->lines()->where('stock_unit_id', $second->id)->sole();
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.count', [$stocktake, $lineSecond]), ['counted_qty' => 9, 'reason' => '找不到一箱,确认丢失'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([10, 2], [$second->fresh()->qty_on_hand, $second->fresh()->qty_frozen]);
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.close', $stocktake))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $stocktake->fresh()->status);
        $second->refresh();
        $this->assertSame([9, 0, 9], [$second->qty_on_hand, $second->qty_frozen, $second->availableQty()]);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $second->id, 'movement_type' => 'adjust', 'qty' => -1, 'qty_before' => 10, 'qty_after' => 9, 'source_type' => 'stocktake', 'source_id' => $stocktake->id]);
        $this->assertDatabaseMissing('stock_ledger', ['stock_unit_id' => $first->id, 'movement_type' => 'adjust']);
        $this->assertSame(['qty_on_hand' => 12, 'qty_reserved' => 0, 'qty_available' => 12], app(StockService::class)->onHand($client->id, $asnLine->id));
        $this->assertSame(0, StockUnit::query()->where('qty_frozen', '>', 0)->count());

        // A later 找不到 opens a new 差异盘点 (the previous one is closed).
        $this->assertSame(1, Stocktake::query()->where('kind', Stocktake::KIND_DISCREPANCY)->count());
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_out_of_stock_keeps_todays_behaviour_the_shortfall_returns_to_available_and_no_stocktake_is_opened(): void
    {
        [$client, , , $asnLine, $units, $order, $pickLines, $operator] = $this->shortPickScene();
        [$first] = $units;

        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $pickLines[$first->id]), ['picked_qty' => 7, 'short_reason' => 'out_of_stock'])
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.outbound.pick_short_recorded', ['short' => 3, 'reason' => __('warehouse.outbound.short_reasons.out_of_stock')]));
        $first->refresh();
        $this->assertSame([3, 0, 0, 3], [$first->qty_on_hand, $first->qty_reserved, $first->qty_frozen, $first->availableQty()]);
        $this->assertSame(['qty_on_hand' => 13, 'qty_reserved' => 2, 'qty_available' => 11], app(StockService::class)->onHand($client->id, $asnLine->id));
        $this->assertSame(0, Stocktake::query()->count());
        $exception = ExceptionRecord::query()->where('type', 'pick_short')->where('order_id', $order->id)->sole();
        $this->assertSame('fulfilment', $exception->source_type);
        $this->assertStringNotContainsString('冻结', $exception->message);
    }
}
