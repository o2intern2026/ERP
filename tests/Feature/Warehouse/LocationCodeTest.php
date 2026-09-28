<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #164: the industry location code WH-ZONE-AISLE-BAY-LEVEL-POSITION — odd bays on the left of the aisle, even on the right,
 * position 1 = left slot, 2 = right; floor areas keep the four-part code. The rack-grid generator, the single form, labels and re-coding.
 */
class LocationCodeTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_the_generator_builds_a_rack_grid_with_six_part_codes_odd_bays_left_and_skips_existing_slots(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse(); // MEL
        $grid = ['warehouse_id' => $warehouse->id, 'zone' => 'b', 'aisle_from' => 1, 'aisle_to' => 2, 'bay_from' => 1, 'bay_to' => 3, 'levels' => 2, 'positions' => 2, 'type' => 'storage', 'bottom_level_one' => 1];

        $this->actingAs($supervisor)->from(route('warehouse.locations.index'))->post(route('warehouse.locations.generate'), $grid)
            ->assertRedirect(route('warehouse.locations.index'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('warehouse.locations.generate.done', ['created' => 24, 'skipped' => 0]));

        $first = Location::query()->where('full_code', 'MEL-B-01-01-1-1')->sole();
        $this->assertSame(['B', '01', '01', 1, 1, 'storage', 'bottom', 'left', true], [$first->zone, $first->aisle, $first->bay, $first->rack_level, $first->position, $first->type, $first->storage_tier, $first->side(), $first->isRack()]);
        $this->assertSame(['standard', 'left'], [Location::query()->where('full_code', 'MEL-B-02-03-2-2')->sole()->storage_tier, Location::query()->where('full_code', 'MEL-B-02-03-2-2')->sole()->side()], 'bay 03 is odd → left; level 2 is standard');
        $this->assertSame('right', Location::query()->where('full_code', 'MEL-B-01-02-1-1')->sole()->side(), 'bay 02 is even → right');
        $this->assertSame([24, 12], [Location::query()->where('zone', 'B')->count(), Location::query()->where('zone', 'B')->where('storage_tier', 'bottom')->count()]);

        // The same grid again changes nothing; a wider grid adds only the new bay.
        $this->actingAs($supervisor)->post(route('warehouse.locations.generate'), $grid)->assertSessionHas('status', __('warehouse.locations.generate.done', ['created' => 0, 'skipped' => 24]));
        $this->actingAs($supervisor)->post(route('warehouse.locations.generate'), ['bay_to' => 4] + $grid)->assertSessionHas('status', __('warehouse.locations.generate.done', ['created' => 8, 'skipped' => 24]));
        $this->assertSame(32, Location::query()->where('zone', 'B')->count());

        // A runaway range is refused before anything is written; an operator may not generate at all.
        $this->actingAs($supervisor)->post(route('warehouse.locations.generate'), ['aisle_to' => 99, 'bay_to' => 99, 'levels' => 9] + $grid)->assertSessionHasErrors('bay_to');
        $this->actingAs($this->staff('warehouse_operator'))->post(route('warehouse.locations.generate'), $grid)->assertForbidden();
        $this->assertSame(32, Location::query()->where('zone', 'B')->count());

        // The page: the code explained, the generator, the 格 column with its side; the list sorts in walk order; no raw lang key.
        $page = $this->actingAs($supervisor)->get(route('warehouse.locations.index'))->assertOk()
            ->assertSee(__('warehouse.locations.generate.title'))->assertSee(__('warehouse.locations.code_hint'))->assertSee(__('warehouse.locations.position'))
            ->assertSee('<code>MEL-B-01-01-1-1</code>', false)->assertSee(__('warehouse.locations.position_option', ['position' => 2, 'side' => __('warehouse.locations.sides.right')]));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.locations\./', $page->getContent());
        $this->assertLessThan(strpos($page->getContent(), 'MEL-B-01-01-1-2'), strpos($page->getContent(), 'MEL-B-01-01-1-1'));
        $this->assertLessThan(strpos($page->getContent(), 'MEL-B-01-02-1-1'), strpos($page->getContent(), 'MEL-B-01-01-2-2'));
    }

    public function test_a_single_location_needs_level_and_slot_together_floor_areas_keep_four_parts_and_a_level_change_recodes_the_slot(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();
        $base = ['warehouse_id' => $warehouse->id, 'zone' => 'C', 'aisle' => '01', 'bay' => '07', 'type' => 'storage'];

        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), $base + ['rack_level' => 2])->assertSessionHasErrors('position');
        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), $base + ['position' => 1])->assertSessionHasErrors('rack_level');
        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), $base + ['rack_level' => 2, 'position' => 2])
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.locations.created', ['code' => 'MEL-C-01-07-2-2']));
        $slot = Location::query()->where('full_code', 'MEL-C-01-07-2-2')->sole();
        $this->assertSame([2, 2, 'left'], [$slot->rack_level, $slot->position, $slot->side()], 'bay 07 is odd → left side; slot 2 = the right slot of that bay');

        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), ['warehouse_id' => $warehouse->id, 'zone' => 'STG', 'aisle' => '01', 'bay' => '02', 'type' => 'staging'])
            ->assertSessionHas('status', __('warehouse.locations.created', ['code' => 'MEL-STG-01-02']));
        $floor = Location::query()->where('full_code', 'MEL-STG-01-02')->sole();
        $this->assertSame([null, null, false], [$floor->rack_level, $floor->position, $floor->isRack()]);

        // Scanning the six-part code (any case) resolves the slot like any location code.
        $this->actingAs($this->staff('warehouse_operator'))->get('/warehouse/scan/resolve?code=mel-c-01-07-2-2')->assertRedirect();
        $this->assertSame($slot->id, Location::query()->scanCode('MEL-c-01-07-2-2')->sole()->id);

        // 批量设置 moving the slot to level 3 re-codes it (Location::booted) and logs the change; the floor area is untouched.
        $this->actingAs($supervisor)->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'C', 'aisle_from' => '01', 'aisle_to' => '01', 'bay_from' => '07', 'bay_to' => '07', 'set_rack_level' => 3])
            ->assertSessionHasNoErrors();
        $this->assertSame('MEL-C-01-07-3-2', $slot->fresh()->full_code);
        $this->assertSame('MEL-STG-01-02', $floor->fresh()->full_code);
    }

    public function test_location_labels_print_the_level_the_slot_and_the_aisle_side_in_english(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();
        $slot = Location::query()->create(['warehouse_id' => $warehouse->id, 'zone' => 'C', 'aisle' => '01', 'bay' => '08', 'rack_level' => 2, 'position' => 2, 'type' => 'storage', 'active' => true]);
        $this->assertSame('MEL-C-01-08-2-2', $slot->full_code, 'a new row without a code gets one from its parts');

        $generator = new BarcodeGeneratorHTML;
        $locations = Location::query()->whereKey([$slot->id, $this->location($warehouse, 'receiving')->id])->orderBy('full_code')->get()->load('warehouse');
        $html = view('warehouse::labels.locations', [
            'locations' => $locations,
            'barcodes' => $locations->mapWithKeys(fn (Location $l) => [$l->id => $generator->getBarcode($l->full_code, BarcodeGeneratorHTML::TYPE_CODE_128, 2, 70)]),
        ])->render();
        $this->assertStringContainsString('Level 2', $html);
        $this->assertStringContainsString('Slot 2 (R)', $html);
        $this->assertStringContainsString('R side of aisle', $html, 'bay 08 is even → right side');
        $this->assertSame(1, substr_count($html, 'Slot '), 'the receiving area prints no slot');
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $html);
        $this->assertStringNotContainsString('pdf.', $html);
        $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id, 'zone' => 'C']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
