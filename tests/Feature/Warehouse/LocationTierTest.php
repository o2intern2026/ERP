<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Picqer\Barcode\BarcodeGeneratorHTML;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #126 (lead request 2026-09-15): storage locations carry a rack level and a storage tier (standard | bottom). The
 * supervisor sets them one by one or in bulk (zone / aisle / bin ranges); every change is logged; location labels print the tier in English.
 */
class LocationTierTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const CJK = '/[\x{3000}-\x{303F}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}]/u';

    public function test_a_location_is_created_with_rack_level_and_tier_and_only_storage_locations_carry_bottom(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();

        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), ['warehouse_id' => $warehouse->id, 'zone' => 'B', 'aisle' => '01', 'bin' => '01', 'type' => 'storage', 'rack_level' => 1, 'storage_tier' => 'bottom'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), ['warehouse_id' => $warehouse->id, 'zone' => 'PF', 'aisle' => '02', 'bin' => '01', 'type' => 'pickface', 'rack_level' => 1, 'storage_tier' => 'bottom'])
            ->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), ['warehouse_id' => $warehouse->id, 'zone' => 'B', 'aisle' => '01', 'bin' => '09', 'type' => 'storage', 'storage_tier' => 'middle'])
            ->assertSessionHasErrors('storage_tier');

        $bottom = Location::query()->where('full_code', 'MEL-B-01-01')->sole();
        $this->assertSame(['bottom', 1], [$bottom->storage_tier, $bottom->rack_level]);
        $this->assertSame('standard', Location::query()->where('full_code', 'MEL-PF-02-01')->value('storage_tier'), 'a pickface never carries the bottom tier');

        $page = $this->actingAs($supervisor)->get(route('warehouse.locations.index'))->assertOk()
            ->assertSee(__('warehouse.locations.rack_level'))->assertSee(__('warehouse.locations.storage_tier'))->assertSee(__('warehouse.storage_tiers.bottom'))
            ->assertSee(__('warehouse.locations.bulk.title'));
        foreach (['warehouse.locations.', 'warehouse.storage_tiers.'] as $rawKey) {
            $page->assertDontSee($rawKey);
        }
        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.locations.index'))->assertOk()->assertDontSee(__('warehouse.locations.bulk.title'));
    }

    public function test_bulk_sets_level_and_tier_by_zone_and_ranges_logs_every_change_and_is_supervisor_only(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();
        $other = $this->warehouse('SYD');
        foreach (['01', '02', '03'] as $aisle) {
            foreach (['01', '02', '03', '04'] as $bin) {
                Location::query()->firstOrCreate(['warehouse_id' => $warehouse->id, 'full_code' => "MEL-C-{$aisle}-{$bin}"], ['zone' => 'C', 'aisle' => $aisle, 'bin' => $bin, 'type' => 'storage', 'active' => true]);
            }
        }
        Location::query()->create(['warehouse_id' => $warehouse->id, 'full_code' => 'MEL-C-01-QA', 'zone' => 'C', 'aisle' => '01', 'bin' => '01Q', 'type' => 'quarantine', 'active' => true]);
        Location::query()->create(['warehouse_id' => $other->id, 'full_code' => 'SYD-C-01-01', 'zone' => 'C', 'aisle' => '01', 'bin' => '01', 'type' => 'storage', 'active' => true]);

        // The warehouse operator may create locations but not bulk-edit tiers.
        $this->actingAs($this->staff('warehouse_operator'))->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'C', 'set_storage_tier' => 'bottom'])->assertForbidden();
        $this->actingAs($this->staff('customer_service'))->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'C', 'set_storage_tier' => 'bottom'])->assertForbidden();
        $this->actingAs($supervisor)->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'C'])->assertSessionHasErrors('set_rack_level');

        // Aisles 1–2, bin 1 (numeric compare: "1" matches "01") → level 1 + bottom.
        $this->actingAs($supervisor)->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'c', 'aisle_from' => '1', 'aisle_to' => '2', 'bin_from' => '1', 'bin_to' => '1', 'set_rack_level' => 1, 'set_storage_tier' => 'bottom'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('warehouse.locations.bulk.done', ['changed' => 2, 'matched' => 2]));
        $this->assertEqualsCanonicalizing(['MEL-C-01-01', 'MEL-C-02-01'], Location::query()->where('storage_tier', 'bottom')->pluck('full_code')->all());
        $this->assertSame(1, Location::query()->where('full_code', 'MEL-C-02-01')->value('rack_level'));
        $this->assertSame('standard', Location::query()->where('full_code', 'SYD-C-01-01')->value('storage_tier'), 'another warehouse is untouched');
        $this->assertSame('standard', Location::query()->where('full_code', 'MEL-C-01-QA')->value('storage_tier'), 'only storage locations are bulk-edited');

        // Level only, whole zone: tiers stay; a second identical run changes nothing.
        $this->actingAs($supervisor)->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'C', 'bin_from' => '02', 'set_rack_level' => 3])
            ->assertSessionHas('status', __('warehouse.locations.bulk.done', ['changed' => 9, 'matched' => 9]));
        $this->actingAs($supervisor)->post(route('warehouse.locations.bulk'), ['warehouse_id' => $warehouse->id, 'zone' => 'C', 'bin_from' => '02', 'set_rack_level' => 3])
            ->assertSessionHas('status', __('warehouse.locations.bulk.done', ['changed' => 0, 'matched' => 9]));
        $this->assertSame(2, Location::query()->where('storage_tier', 'bottom')->count());

        $log = Activity::query()->where('subject_type', Location::class)->where('subject_id', Location::query()->where('full_code', 'MEL-C-01-01')->value('id'))->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['bottom', 1], [$log->properties['attributes']['storage_tier'], $log->properties['attributes']['rack_level']]);
        $this->assertSame('standard', $log->properties['old']['storage_tier']);
        $this->assertSame($supervisor->id, $log->causer_id);
        $this->assertSame(9, Activity::query()->where('subject_type', Location::class)->where('event', 'updated')->where('properties->attributes->rack_level', 3)->count());
    }

    public function test_location_labels_print_the_level_and_the_bottom_tier_in_english(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $warehouse = $this->warehouse();
        $bottom = Location::query()->create(['warehouse_id' => $warehouse->id, 'full_code' => 'MEL-B-01-01', 'zone' => 'B', 'aisle' => '01', 'bin' => '01', 'type' => 'storage', 'storage_tier' => 'bottom', 'rack_level' => 1, 'active' => true]);
        $standard = Location::query()->where('full_code', 'MEL-A-01-01')->sole();

        $generator = new BarcodeGeneratorHTML;
        $locations = Location::query()->whereKey([$bottom->id, $standard->id])->orderBy('full_code')->get()->load('warehouse');
        $html = view('warehouse::labels.locations', [
            'locations' => $locations,
            'barcodes' => $locations->mapWithKeys(fn (Location $l) => [$l->id => $generator->getBarcode($l->full_code, BarcodeGeneratorHTML::TYPE_CODE_128, 2, 70)]),
        ])->render();
        $this->assertStringContainsString('BOTTOM LEVEL', $html);
        $this->assertStringContainsString('Level 1', $html);
        $this->assertSame(1, substr_count($html, 'BOTTOM LEVEL'), 'only the bottom location prints the tier');
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html);
        $this->assertStringNotContainsString('pdf.', $html);

        $this->actingAs($supervisor)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_demo_seed_marks_bin_01_of_zone_a_as_the_bottom_level_and_is_idempotent(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $mel = Warehouse::query()->where('code', 'MEL')->sole();
        $this->assertSame(['bottom', 1], [Location::query()->where('warehouse_id', $mel->id)->where('full_code', 'MEL-A-03-01')->value('storage_tier'), Location::query()->where('full_code', 'MEL-A-03-01')->value('rack_level')]);
        $this->assertSame(['standard', 4], [Location::query()->where('full_code', 'MEL-A-05-04')->value('storage_tier'), Location::query()->where('full_code', 'MEL-A-05-04')->value('rack_level')]);
        $this->assertSame(5, Location::query()->where('storage_tier', 'bottom')->count());
        $this->assertNull(Location::query()->where('full_code', 'MEL-RCV-01-01')->value('rack_level'));
        $this->assertSame(26, Location::query()->where('warehouse_id', $mel->id)->count());
    }
}
