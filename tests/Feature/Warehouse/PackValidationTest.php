<?php

namespace Tests\Feature\Warehouse;

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
 * Audit 2026-09-22 OUTBOUND-09 (CR #141): a pack row with a weight but no type was dropped silently and dims could be left empty (the carrier
 * quoted 0 mm). A weighed row now needs its type and all three dims, refusals name the row, and the form shows a live summary before posting.
 */
class PackValidationTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_weighed_rows_need_type_and_dims_and_the_form_summarises_before_posting(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'PV1', 'cartons' => 8, 'weight_kg' => 40]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 4]]);
        $fulfilment = (int) DB::table('fulfilments')->where('order_id', $order->id)->value('id');
        ['tasks' => $tasks] = app(OutboundService::class)->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id);
        app(OutboundService::class)->confirmPick($tasks->first()->lines->first(), 4, $operator->id);
        $this->assertSame('done', WarehouseTask::query()->where('fulfilment_id', $fulfilment)->where('task_type', 'pick')->value('status'));

        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fulfilment))->assertOk()
            ->assertSee('id="pack-summary"', false)->assertSee(__('warehouse.outbound.summary_label'))->assertSee('id="pack-submit"', false);

        // Weight without a type (row 2) and without dims (row 1) → both refused, naming the rows; nothing packed.
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [
            ['package_type' => 'carton', 'weight_kg' => '12.5'],
            ['package_type' => '', 'weight_kg' => '3', 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 100],
        ]])->assertSessionHasErrors([
            'packages.0.length_mm' => __('warehouse.outbound.errors.row_dims_required', ['row' => 1]),
            'packages.1.package_type' => __('warehouse.outbound.errors.row_type_required', ['row' => 2]),
        ]);
        $this->assertSame(0, Package::query()->withoutGlobalScopes()->count());
        $this->assertMatchesRegularExpression('/\p{Han}/u', __('warehouse.outbound.errors.row_dims_required', ['row' => 1]));

        // A partial dimension set is refused too; the refused page re-renders with the row error above the table.
        $response = $this->actingAs($operator)->from(route('warehouse.outbound.pack.form', $fulfilment))->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [
            ['package_type' => 'carton', 'weight_kg' => '12.5', 'length_mm' => 600, 'width_mm' => 400],
        ]])->assertRedirect(route('warehouse.outbound.pack.form', $fulfilment))->assertSessionHasErrors('packages.0.length_mm');
        $this->actingAs($operator)->get(route('warehouse.outbound.pack.form', $fulfilment))->assertOk()->assertSee(__('warehouse.outbound.errors.row_dims_required', ['row' => 1]));

        // Spare rows with nothing typed are still ignored; complete rows pack.
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilment), ['packages' => [
            ['package_type' => 'carton', 'qty' => 2, 'weight_kg' => '12.5', 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 400],
            ['package_type' => '', 'qty' => 1, 'weight_kg' => '', 'length_mm' => '', 'width_mm' => '', 'height_mm' => ''],
            ['package_type' => 'carton', 'weight_kg' => '', 'length_mm' => '', 'width_mm' => '', 'height_mm' => ''],
        ]])->assertRedirect(route('warehouse.outbound.index'))->assertSessionHasNoErrors();
        $packages = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilment)->get();
        $this->assertCount(2, $packages);
        $this->assertSame([600, 400, 400], [$packages[0]->length_mm, $packages[0]->width_mm, $packages[0]->height_mm]);
    }
}
