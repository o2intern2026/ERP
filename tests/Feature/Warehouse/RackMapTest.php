<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Services\MoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** CHANGE_REQUESTS #165 库位图: one zone's racks per aisle side as a bay × level grid of slots coloured by their stock; floor areas listed. */
class RackMapTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_the_map_draws_the_zone_grid_colours_slots_by_stock_and_links_each_slot_to_the_stock_list(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $client = $this->client();
        $warehouse = $this->warehouse(); // MEL: floor areas RCV / A-01-01 / A-01-02 / PF / QA
        $this->actingAs($supervisor)->post(route('warehouse.locations.generate'), ['warehouse_id' => $warehouse->id, 'zone' => 'B', 'aisle_from' => 1, 'aisle_to' => 1, 'bay_from' => 1, 'bay_to' => 2, 'levels' => 2, 'positions' => 2, 'type' => 'storage', 'bottom_level_one' => 1])->assertSessionHasNoErrors();
        ['asn' => $asn, 'lines' => $asnLines, 'units' => $units] = $this->stockedAsn($client, $warehouse, [['mark' => 'MAP1', 'cartons' => 8, 'weight_kg' => 40], ['mark' => 'MAP2', 'cartons' => 5, 'weight_kg' => 20]]);
        $left = Location::query()->where('full_code', 'MEL-B-01-01-1-1')->sole();   // bay 01 (odd → left rack), level 1, slot 1
        $right = Location::query()->where('full_code', 'MEL-B-01-02-2-2')->sole();  // bay 02 (even → right rack), level 2, slot 2
        app(MoveService::class)->move($units[0]->fresh(), $left);
        app(MoveService::class)->move($units[1]->fresh(), $right);
        $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[1]->id, 'qty' => 2]]); // reserves 2 of MAP2 → the right slot is "reserved"

        $page = $this->actingAs($supervisor)->get(route('warehouse.map.index', ['warehouse_id' => $warehouse->id, 'zone' => 'B']))->assertOk();
        $html = $page->getContent();
        // One SVG per aisle side; the occupied slot is green with its carton count, the reserved one blue, an empty one grey; bottom level slots carry the gold border.
        $page->assertSee('data-aisle="01" data-side="left"', false)->assertSee('data-aisle="01" data-side="right"', false)
            ->assertSee('data-code="MEL-B-01-01-1-1" data-state="occupied"', false)
            ->assertSee('data-code="MEL-B-01-02-2-2" data-state="reserved"', false)
            ->assertSee('data-code="MEL-B-01-01-2-1" data-state="empty"', false)
            ->assertSee(__('warehouse.map.tooltip', ['units' => 1, 'cartons' => 8, 'client' => $client->name]))
            ->assertSee(__('warehouse.map.reserved', ['cartons' => 2]))
            ->assertSee(__('warehouse.map.zone_occupancy', ['occupied' => 2, 'slots' => 8, 'percent' => 25]))
            ->assertSee(__('warehouse.map.total', ['occupied' => 2, 'slots' => 8]))
            ->assertSee(__('warehouse.map.side_left'))->assertSee(__('warehouse.map.side_right'))
            ->assertSee(__('warehouse.map.level', ['level' => 2]))->assertSee(__('warehouse.map.bay', ['bay' => '02']));
        $this->assertMatchesRegularExpression('/<rect[^>]*stroke="#f9a825" stroke-width="3" data-code="MEL-B-01-01-1-1"/', $html, 'level 1 is the bottom tier → gold border');
        $this->assertStringContainsString('>8</text>', $html, 'the occupied slot prints its cartons');
        $this->assertStringContainsString(e(route('warehouse.index', ['warehouse_id' => $warehouse->id, 'location' => 'MEL-B-01-01-1-1'])), $html, 'a slot links to the stock list filtered on its code');
        // Floor areas are listed with their state; the receiving dock is empty now that both units moved on.
        $page->assertSee(__('warehouse.map.floor_title'))->assertSee('data-code="MEL-RCV-01-01" data-state="empty"', false);
        $this->assertDoesNotMatchRegularExpression('/warehouse\.(map|locations|storage_tiers)\./', $html);

        // Default zone = the first rack zone; the stock and config pages link here; the nav carries the entry; a zone without racks says so.
        $this->actingAs($supervisor)->get(route('warehouse.map.index', ['warehouse_id' => $warehouse->id]))->assertOk()->assertSee('data-code="MEL-B-01-01-1-1"', false);
        $this->actingAs($supervisor)->get(route('warehouse.map.index', ['warehouse_id' => $warehouse->id, 'zone' => 'zz']))->assertOk()->assertSee(__('warehouse.map.no_racks', ['zone' => 'ZZ']));
        $this->actingAs($supervisor)->get(route('warehouse.index'))->assertOk()->assertSee(__('warehouse.map.link'))->assertSee(__('warehouse.nav_map'));
        $this->actingAs($this->staff('finance'))->get(route('warehouse.map.index'))->assertOk();
        $this->actingAs($this->clientUser($client))->get(route('warehouse.map.index'))->assertForbidden();
    }
}
