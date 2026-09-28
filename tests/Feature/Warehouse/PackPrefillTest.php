<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #162: the 打包 form opens prefilled from the picked units — a whole pallet with its receiving measurements, cartons with the
 * goods line's declared weight and dims — each row naming its unit and source; a unit without data is a red row left for hand entry.
 */
class PackPrefillTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_the_pack_form_opens_prefilled_from_the_picked_units_and_flags_a_unit_without_data(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines, 'units' => $units] = $this->stockedAsn($client, $warehouse, [
            ['mark' => 'PF1', 'cartons' => 8, 'weight_kg' => 40],  // cartons: 5 kg each + dims declared on the goods line
            ['mark' => 'PF2', 'cartons' => 6, 'weight_kg' => 30],  // cartons: weight known, no dims anywhere → red row
            ['mark' => 'PF3', 'cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1400, 'weight_kg' => 320]]], // whole pallet
        ]);
        AsnLine::query()->whereKey($asnLines[0]->id)->update(['weight_kg' => 40, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 200]);
        AsnLine::query()->whereKey($asnLines[1]->id)->update(['weight_kg' => 30, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null]);
        $orderA = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $orderB = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[1]->id, 'qty' => 2]]);
        $orderC = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[2]->id, 'qty' => 10]]);
        [$fA, $fB, $fC] = array_map(fn ($o) => (int) DB::table('fulfilments')->where('order_id', $o->id)->value('id'), [$orderA, $orderB, $orderC]);

        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$orderA->id, $orderB->id, $orderC->id]])->assertRedirect();
        foreach (WarehouseTask::query()->where('task_type', 'pick')->whereIn('fulfilment_id', [$fA, $fB, $fC])->get() as $task) {
            foreach ($task->lines as $line) {
                app(OutboundService::class)->confirmPick($line, (int) $line->required_qty, $operator->id);
            }
        }

        // A: 3 cartons at 5 kg, 400×300×200 from the goods line, the unit named with its source badge, one spare blank row; no raw lang key.
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fA))->assertOk()
            ->assertSee('name="packages[0][qty]" value="3"', false)
            ->assertSee('name="packages[0][weight_kg]" value="5"', false)
            ->assertSee('name="packages[0][length_mm]" value="400"', false)
            ->assertSee('name="packages[0][height_mm]" value="200"', false)
            ->assertSee('name="packages[1][weight_kg]" value=""', false)
            ->assertSee($units[0]->label_code)
            ->assertSee(__('warehouse.outbound.prefill_sources.asn_line'))
            ->assertSee(__('warehouse.outbound.prefill_hint'))
            ->assertDontSee(__('warehouse.outbound.prefill_missing_row'));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.outbound\.prefill/', $page->getContent());
        $this->assertMatchesRegularExpression('/name="packages\[0\]\[package_type\]"[^>]*>.*?<option value="carton" selected/s', $page->getContent());

        // B: the weight (30 kg / 6) is known but no dims anywhere → the dims boxes stay empty, the row is red and the unit is named above.
        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fB))->assertOk()
            ->assertSee('name="packages[0][qty]" value="2"', false)
            ->assertSee('name="packages[0][weight_kg]" value="5"', false)
            ->assertSee('name="packages[0][length_mm]" value=""', false)
            ->assertSee(__('warehouse.outbound.prefill_missing_row'))
            ->assertSee(__('warehouse.outbound.prefill_missing', ['units' => $units[1]->label_code]));

        // C: the whole pallet → one 托盘 row with the receiving measurements, source 收货实测.
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fC))->assertOk()
            ->assertSee('name="packages[0][qty]" value="1"', false)
            ->assertSee('name="packages[0][weight_kg]" value="320"', false)
            ->assertSee('name="packages[0][length_mm]" value="1200"', false)
            ->assertSee('name="packages[0][height_mm]" value="1400"', false)
            ->assertSee(__('warehouse.outbound.prefill_sources.unit'));
        $this->assertMatchesRegularExpression('/name="packages\[0\]\[package_type\]"[^>]*>.*?<option value="pallet" selected/s', $page->getContent());

        // Posting the prefilled row as it is packs A exactly as 批量打包 would (3 cartons of 5 kg); a refused post re-renders the typed values, not the prefill.
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fA), ['packages' => [['package_type' => 'carton', 'qty' => 3, 'weight_kg' => 5, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 200]]])->assertRedirect();
        $packages = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fA)->orderBy('id')->get();
        $this->assertSame([3, 'carton', 5.0, 400], [$packages->count(), $packages[0]->package_type, (float) $packages[0]->weight_kg, (int) $packages[0]->length_mm]);
        $this->actingAs($operator)->from(route('warehouse.outbound.pack.form', $fB))
            ->post(route('warehouse.outbound.pack', $fB), ['packages' => [['package_type' => 'carton', 'qty' => 2, 'weight_kg' => 7.5, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 0]]])
            ->assertRedirect(route('warehouse.outbound.pack.form', $fB))->assertSessionHasErrors();
        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fB))->assertOk()
            ->assertSee('name="packages[0][weight_kg]" value="7.5"', false)
            ->assertDontSee(__('warehouse.outbound.prefill_hint'));
    }
}
