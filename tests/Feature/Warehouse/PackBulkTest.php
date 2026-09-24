<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Models\Wave;
use App\Modules\Warehouse\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #155 批量打包: ticked picked batches are packed from their picked lines; a batch whose goods lack a weight or dims is named, not guessed. */
class PackBulkTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_ticked_picked_batches_are_packed_from_their_lines_and_a_batch_without_dims_is_named(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'PB1', 'cartons' => 8, 'weight_kg' => 40], ['mark' => 'PB2', 'cartons' => 6, 'weight_kg' => 30]]);
        AsnLine::query()->whereKey($asnLines[0]->id)->update(['weight_kg' => 40, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 200]); // 5 kg per carton
        AsnLine::query()->whereKey($asnLines[1]->id)->update(['weight_kg' => 30, 'length_mm' => null, 'width_mm' => null, 'height_mm' => null]); // no dims → by hand
        $orderA = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 3]]);
        $orderB = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[1]->id, 'qty' => 2]]);
        $fulfilmentA = (int) DB::table('fulfilments')->where('order_id', $orderA->id)->value('id');
        $fulfilmentB = (int) DB::table('fulfilments')->where('order_id', $orderB->id)->value('id');

        $this->actingAs($operator)->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'order_ids' => [$orderA->id, $orderB->id]])->assertRedirect();
        $wave = Wave::query()->firstOrFail();
        foreach (WarehouseTask::query()->where('task_type', 'pick')->whereIn('fulfilment_id', [$fulfilmentA, $fulfilmentB])->get() as $task) {
            foreach ($task->lines as $line) {
                app(OutboundService::class)->confirmPick($line, (int) $line->required_qty, $operator->id);
            }
        }
        $this->assertSame(['done', 'done'], WarehouseTask::query()->where('task_type', 'pick')->whereIn('fulfilment_id', [$fulfilmentA, $fulfilmentB])->pluck('status')->all(), 'both pick tasks are done');

        // The board: a checkbox per 待打包 batch bound to the bulk form; no raw lang key.
        $page = $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()
            ->assertSee('id="pack-bulk"', false)->assertSee('name="fulfilment_ids[]" value="'.$fulfilmentA.'" form="pack-bulk"', false)
            ->assertSee(__('warehouse.outbound.pack_bulk.title'));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.outbound\.pack_bulk\./', $page->getContent());

        // Nothing ticked → refusal. Both ticked → A packed as 3 cartons of 5 kg 400×300×200 (one Package row each), B named for its missing dims.
        $this->actingAs($operator)->post(route('warehouse.outbound.pack_bulk'), [])->assertSessionHasErrors('fulfilment_ids');
        $this->actingAs($operator)->post(route('warehouse.outbound.pack_bulk'), ['fulfilment_ids' => [$fulfilmentA, $fulfilmentB]])
            ->assertRedirect(route('warehouse.outbound.index'))
            ->assertSessionHas('status', __('warehouse.outbound.pack_bulk.done', ['count' => 1, 'pieces' => 3]))
            ->assertSessionHasErrors('pack');
        $this->assertStringContainsString('#'.$fulfilmentB, session('errors')->first('pack'));
        $this->assertStringContainsString('缺重量或尺寸', session('errors')->first('pack'));
        $packages = Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentA)->orderBy('id')->get();
        $this->assertCount(3, $packages);
        $this->assertSame(['carton', 5.0, 400, 300, 200, 'PKG-'.$fulfilmentA.'-01'], [$packages[0]->package_type, (float) $packages[0]->weight_kg, (int) $packages[0]->length_mm, (int) $packages[0]->width_mm, (int) $packages[0]->height_mm, $packages[0]->carton_label]);
        $this->assertSame(0, Package::query()->withoutGlobalScopes()->where('fulfilment_id', $fulfilmentB)->count());

        // B still packs by hand with measured values; a second bulk post finds A already packed and B still without dims.
        $this->actingAs($operator)->post(route('warehouse.outbound.pack', $fulfilmentB), ['packages' => [['package_type' => 'carton', 'qty' => 2, 'weight_kg' => 5, 'length_mm' => 300, 'width_mm' => 200, 'height_mm' => 150]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($operator)->post(route('warehouse.outbound.pack_bulk'), ['fulfilment_ids' => [$fulfilmentA, $fulfilmentB]])->assertSessionHasErrors('pack')->assertSessionMissing('status');
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.outbound.pack_bulk'), ['fulfilment_ids' => [$fulfilmentA]])->assertForbidden();
    }
}
