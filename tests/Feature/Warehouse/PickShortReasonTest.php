<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Warehouse\Models\Stocktake;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 OUTBOUND-08 (CR #141): a short pick was recorded silently — same flash, plain "15 / 14", English exception text. Now the
 * wave page asks for a reason (缺货 / 破损 / 找不到 / 其他 + note) and a confirm() when 实拣 < 应拣, the flash and a badge say "少拣", and the
 * reason lands in the pick_short exception message. Since CR #142 (lead decision 2026-09-22) the reason 找不到 freezes the shortfall on the
 * unit and opens a 差异盘点 line instead of returning it to available — PickShortNotFoundTest covers that path in depth.
 */
class PickShortReasonTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_short_pick_needs_a_reason_and_is_shown_as_such(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'SP1', 'cartons' => 20, 'weight_kg' => 100]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 15]]);
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$order->id]])->assertRedirect();
        $wave = Wave::query()->firstOrFail();
        $task = WarehouseTask::query()->where('task_type', 'pick')->firstOrFail();
        $line = $task->lines()->firstOrFail();

        // The row carries the reason select, note and the confirm text with the shortfall placeholders.
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk();
        $page->assertSee('name="short_reason"', false)->assertSee('name="short_note"', false)->assertSee('data-required="15"', false)
            ->assertSee(__('warehouse.outbound.short_reasons.not_found'))->assertSee(__('warehouse.outbound.short_reasons.damaged'))->assertSee('class="inline pick-form"', false);

        // 14 of 15 without a reason → refused in Chinese, nothing picked.
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $line), ['picked_qty' => 14])
            ->assertSessionHasErrors(['short_reason' => __('warehouse.outbound.errors.short_reason_required', ['required' => 15, 'picked' => 14])]);
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $line), ['picked_qty' => 14, 'short_reason' => 'lost'])->assertSessionHasErrors('short_reason');
        $this->assertNull($line->fresh()->confirmed_at);
        $this->assertSame(0, ExceptionRecord::query()->where('type', 'pick_short')->count());

        // With the reason: recorded, distinct flash (找不到 names the 差异盘点 it opened — CR #142), exception message in Chinese with the reason and note.
        $response = $this->actingAs($operator)->post(route('warehouse.outbound.pick', $line), ['picked_qty' => 14, 'short_reason' => 'not_found', 'short_note' => '库位空了'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $stocktake = Stocktake::query()->where('kind', Stocktake::KIND_DISCREPANCY)->sole();
        $response->assertSessionHas('status', __('warehouse.outbound.pick_short_frozen_recorded', ['short' => 1, 'stocktake' => $stocktake->stocktake_no]));
        $this->assertSame(14, $line->fresh()->completed_qty);
        $exception = ExceptionRecord::query()->where('type', 'pick_short')->where('order_id', $order->id)->sole();
        $this->assertStringContainsString($task->task_no, $exception->message);
        $this->assertStringContainsString('应拣 15,实拣 14,少 1 箱', $exception->message);
        $this->assertStringContainsString('原因:找不到 · 库位空了', $exception->message);
        $this->assertDoesNotMatchRegularExpression('/picked \d+ of \d+/', $exception->message);

        // Badge on the row and on the card footer next to 打包; the 找不到 carton is frozen, not available (CR #142 — 6 on hand, 5 available).
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.waves.show', $wave))->assertOk();
        $page->assertSee(__('warehouse.outbound.short_badge', ['short' => 1]))->assertSee(__('warehouse.outbound.short_card_badge', ['lines' => 1, 'short' => 1]))->assertSee(__('warehouse.outbound.pack'));
        $this->assertSame(['qty_on_hand' => 6, 'qty_reserved' => 0, 'qty_available' => 5], app(StockService::class)->onHand($client->id, $asnLines[0]->id));
        $this->assertSame('done', $task->fresh()->status);

        // A full pick keeps the plain flash and no badge.
        $order2 = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$order2->id]])->assertRedirect();
        $line2 = WarehouseTask::query()->where('order_id', $order2->id)->firstOrFail()->lines()->firstOrFail();
        $this->actingAs($operator)->post(route('warehouse.outbound.pick', $line2), ['picked_qty' => 2])->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.outbound.pick_confirmed'));
        $this->assertSame(1, ExceptionRecord::query()->where('type', 'pick_short')->count());
        $this->assertSame(1, DB::table('exceptions')->where('type', 'pick_short')->count());
    }
}
