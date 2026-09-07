<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\StockSnapshot;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B10a: §4.7 #7 (daily, any day, by pallet type), #20 (pallet source), #21 (pickface slots, not pallets). */
class SnapshotTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_snapshot_classifies_pallets_sources_and_pickface_slots_and_can_be_re_run(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'SNAP1', 'size' => '40', 'unpack_mode' => 'pallet']]]);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => 70]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 70, 'units' => [
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'chep'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1700, 'weight_kg' => 400, 'pallet_source' => 'warehouse_plain'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 300, 'pallet_source' => 'client_own'],
            ['unit_type' => 'carton', 'carton_qty' => 10],
        ]], $this->location($warehouse, 'receiving'));
        app(PutawayService::class)->putaway($units[0], $this->location($warehouse, 'storage'));
        app(PutawayService::class)->putaway($units[1], $this->location($warehouse, 'storage'));
        app(PutawayService::class)->putaway($units[2], $this->location($warehouse, 'pickface'));
        app(PutawayService::class)->putaway($units[3], $this->location($warehouse, 'pickface'));

        $date = Carbon::parse('2026-09-07');
        $this->assertSame(4, app(SnapshotService::class)->take($date));
        $this->artisan('stock:snapshot --date=2026-09-07')->assertSuccessful(); // re-run replaces, never duplicates
        $this->assertSame(4, StockSnapshot::query()->where('snapshot_date', '2026-09-07')->count());

        [$summary] = app(SnapshotService::class)->summary($date)->all();
        $this->assertSame(3, $summary['pallets']);
        $this->assertEquals(['standard' => 1, 'oversize_high' => 1, 'pickface' => 1], $summary['pallets_by_class']);
        $this->assertEquals(['chep' => 1, 'warehouse_plain' => 1, 'client_own' => 1], $summary['pallets_by_source']);
        $this->assertSame(1, $summary['pickface_slots']); // two units share one pickface slot → billed once (#21)
        $this->assertSame(1, $summary['carton_units']);
        $this->assertSame(70, $summary['cartons']);

        $this->assertDatabaseHas('stock_snapshots', ['snapshot_date' => '2026-09-07', 'stock_unit_id' => $units[1]->id, 'pallet_class' => 'oversize_high', 'pallet_source' => 'warehouse_plain', 'location_type' => 'storage']);
        $this->assertSame(0, StockSnapshot::query()->where('snapshot_date', '2026-09-06')->count());
    }
}
