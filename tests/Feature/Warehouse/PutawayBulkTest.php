<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #149 批量上架: ticked units → one scanned location; refused units are named, the rest are put away. */
class PutawayBulkTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_ticked_units_go_to_one_location_and_a_refused_unit_is_named_while_the_others_are_put_away(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        [$standard, $bottom] = app(AsnService::class)->addLines($asn, [
            ['description' => 'Standard goods', 'expected_cartons' => 6, 'consignment_mark' => 'STD-1'],
            ['description' => 'Bottom goods', 'expected_cartons' => 5, 'consignment_mark' => 'BTM-1', 'storage_tier' => 'bottom', 'storage_tier_source' => 'staff'],
        ]);
        $receiving = $this->location($warehouse, 'receiving');
        $stdUnits = app(ReceivingService::class)->receiveLine($standard, ['received_cartons' => 6, 'units' => [['unit_type' => 'carton', 'carton_qty' => 3], ['unit_type' => 'carton', 'carton_qty' => 3]]], $receiving);
        $bottomUnits = app(ReceivingService::class)->receiveLine($bottom, ['received_cartons' => 5, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 5]]], $receiving);
        $storage = $this->location($warehouse, 'storage');
        $this->assertSame('standard', $storage->storage_tier ?: 'standard');

        // The page: a checkbox per unit bound to the bulk form, the bulk form itself, no raw lang key.
        $page = $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()
            ->assertSee('id="bulk-putaway"', false)->assertSee('name="unit_ids[]" value="'.$stdUnits[0]->id.'" form="bulk-putaway"', false)
            ->assertSee(__('warehouse.putaway.bulk.title'))->assertSee(__('warehouse.putaway.bulk.select_all'))
            // CHANGE_REQUESTS #159: the target is a select over the warehouse's existing locations, not a typed code.
            ->assertSee('<select name="bulk_location_code" id="bulk-location">', false)->assertSee('<option value="'.$storage->full_code.'"', false)->assertSee(__('warehouse.putaway.bulk.location_choose'));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.putaway\./', $page->getContent());

        // Nothing ticked → Chinese refusal; the bottom pallet into a standard location without a reason is refused, the two cartons go.
        $this->actingAs($supervisor)->post(route('warehouse.putaway.bulk'), ['bulk_location_code' => $storage->full_code])->assertSessionHasErrors('unit_ids');
        $ids = [$stdUnits[0]->id, $stdUnits[1]->id, $bottomUnits[0]->id];
        $this->actingAs($supervisor)->from(route('warehouse.putaway.index'))->post(route('warehouse.putaway.bulk'), ['unit_ids' => $ids, 'bulk_location_code' => $storage->full_code])
            ->assertRedirect(route('warehouse.putaway.index'))
            ->assertSessionHas('status', __('warehouse.putaway.bulk.done', ['count' => 2, 'location' => $storage->full_code]))
            ->assertSessionHasErrors('unit_ids');
        $this->assertStringContainsString($bottomUnits[0]->label_code, session('errors')->first('unit_ids'));
        $this->assertStringContainsString('1 个未上架', session('errors')->first('unit_ids'));
        $this->assertTrue($stdUnits[0]->fresh()->putaway_completed);
        $this->assertTrue($stdUnits[1]->fresh()->putaway_completed);
        $this->assertSame($storage->id, (int) $stdUnits[1]->fresh()->location_id);
        $this->assertFalse($bottomUnits[0]->fresh()->putaway_completed, 'the tier mismatch without a reason is refused, as by row');

        // With a reason the bottom pallet goes too; an already put-away unit and an unknown code are simply not there / named.
        $this->actingAs($supervisor)->post(route('warehouse.putaway.bulk'), ['unit_ids' => [$bottomUnits[0]->id, $stdUnits[0]->id], 'bulk_location_code' => $storage->full_code, 'bulk_tier_reason' => '底层已满'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.putaway.bulk.done', ['count' => 1, 'location' => $storage->full_code]));
        $this->assertTrue($bottomUnits[0]->fresh()->putaway_completed);
        $this->assertSame(0, StockUnit::query()->withoutGlobalScopes()->where('putaway_completed', false)->count());
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.putaway.bulk'), ['unit_ids' => [1], 'bulk_location_code' => 'X'])->assertForbidden();

    }

    /** CHANGE_REQUESTS #151: the search box narrows the pending units; 全选 + 批量上架 then clears the whole result in one click. */
    public function test_search_narrows_the_pending_units_by_mark_description_asn_or_client_and_the_result_is_put_away_in_one_click(): void
    {
        $client = $this->client(['name' => 'Search Co', 'code' => 'SRCH']);
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asnA = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        $asnB = app(AsnService::class)->create(['client_id' => $this->client(['name' => 'Other Co'])->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$chairs, $lamps] = app(AsnService::class)->addLines($asnA, [
            ['description' => 'Chairs', 'expected_cartons' => 4, 'consignment_mark' => 'CW1001-1'],
            ['description' => 'Lamps', 'expected_cartons' => 2, 'consignment_mark' => 'CW1002'],
        ]);
        [$tables] = app(AsnService::class)->addLines($asnB, [['description' => 'Tables', 'expected_cartons' => 3, 'consignment_mark' => 'TB-9']]);
        $receiving = $this->location($warehouse, 'receiving');
        $units = [];
        foreach ([[$chairs, [2, 2]], [$lamps, [2]], [$tables, [3]]] as [$line, $cartons]) {
            $units[$line->id] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => array_sum($cartons), 'units' => array_map(fn ($q) => ['unit_type' => 'carton', 'carton_qty' => $q], $cartons)], $receiving);
        }
        $chairUnits = $units[$chairs->id];
        $tableUnit = $units[$tables->id][0];

        // By mark root, by description, by ASN number, by client — the other client's unit never appears; an unknown term says so.
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index', ['q' => 'CW1001']))->assertOk()
            ->assertSee($chairUnits[0]->label_code)->assertSee($chairUnits[1]->label_code)->assertDontSee($tableUnit->label_code)
            ->assertSee(__('warehouse.putaway.search_count', ['q' => 'CW1001', 'count' => 2, 'page' => 2]));
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index', ['q' => 'lamp']))->assertOk()->assertSee($units[$lamps->id][0]->label_code)->assertDontSee($chairUnits[0]->label_code);
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index', ['q' => $asnA->asn_no]))->assertOk()->assertSee($chairUnits[0]->label_code)->assertSee($units[$lamps->id][0]->label_code)->assertDontSee($tableUnit->label_code);
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index', ['q' => 'search co']))->assertOk()->assertSee($chairUnits[0]->label_code)->assertDontSee($tableUnit->label_code);
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index', ['q' => 'nothing-here']))->assertOk()->assertSee(__('warehouse.putaway.search_empty'))->assertDontSee('id="bulk-putaway"', false);
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()->assertSee($tableUnit->label_code)->assertDontSee(__('warehouse.putaway.search_clear'));

        // The whole search result goes away in one click; the redirect keeps the search, which is now empty.
        $storage = $this->location($warehouse, 'storage');
        $ids = collect($chairUnits)->pluck('id')->all();
        $this->actingAs($supervisor)->from(route('warehouse.putaway.index', ['q' => 'CW1001']))->post(route('warehouse.putaway.bulk'), ['unit_ids' => $ids, 'bulk_location_code' => $storage->full_code])
            ->assertRedirect(route('warehouse.putaway.index', ['q' => 'CW1001']))->assertSessionHasNoErrors();
        $this->assertSame(0, StockUnit::query()->withoutGlobalScopes()->whereKey($ids)->where('putaway_completed', false)->count());
        $this->assertSame(2, StockUnit::query()->withoutGlobalScopes()->where('putaway_completed', false)->count(), 'the lamp and the other client\'s table wait');
    }
}
