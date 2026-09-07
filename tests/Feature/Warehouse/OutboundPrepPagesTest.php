<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Stocktake;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B10a / B10b / B11 / B14 pages: snapshots, stocktake flow, scan resolver, PDF labels, warehouse switch, move / quarantine, new warehouse. */
class OutboundPrepPagesTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function stock(int $clientId, Warehouse $warehouse, int $cartons, string $mark = 'MARK-1'): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $clientId, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => $cartons, 'consignment_mark' => $mark]]);
        [$unit] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $cartons, 'units' => [['unit_type' => 'pallet', 'carton_qty' => $cartons, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1200, 'weight_kg' => 200, 'pallet_source' => 'chep']]], $this->location($warehouse, 'receiving'));
        app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));

        return [$asn, $line, $unit->fresh()];
    }

    public function test_snapshot_page_shows_the_day(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $this->stock($client->id, $warehouse, 12);
        app(SnapshotService::class)->take(today());

        $this->actingAs($this->staff('finance'))->get('/warehouse/snapshots?date='.today()->toDateString())->assertOk()->assertSee($client->name)->assertSee(__('warehouse.pallet_sources.chep'));
        $this->actingAs($this->staff('finance'))->get('/warehouse/snapshots?date=2020-01-01')->assertOk()->assertSee(__('warehouse.snapshots.empty'));
    }

    public function test_stocktake_flow_through_the_pages(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        [, , $unit] = $this->stock($client->id, $warehouse, 10);
        $supervisor = $this->staff('warehouse_supervisor');

        $this->actingAs($supervisor)->get('/warehouse/stocktakes/create')->assertOk();
        $this->actingAs($supervisor)->post('/warehouse/stocktakes', ['warehouse_id' => $warehouse->id, 'location_code' => 'MEL-A-01-01'])->assertRedirect();
        $stocktake = Stocktake::query()->firstOrFail();
        $this->assertSame(1, $stocktake->lines()->count());

        $this->actingAs($supervisor)->get(route('warehouse.stocktakes.show', $stocktake))->assertOk()->assertSee($unit->label_code);
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.scan', $stocktake), ['code' => strtolower($unit->label_code)])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocktake_lines', ['stocktake_id' => $stocktake->id, 'counted_qty' => 10, 'variance' => 0, 'scanned' => 1]);

        $line = $stocktake->lines()->firstOrFail();
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.count', [$stocktake, $line]), ['counted_qty' => 9])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.close', $stocktake))->assertSessionHasErrors('close');
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.count', [$stocktake, $line]), ['counted_qty' => 9, 'reason' => 'one carton missing'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.close', $stocktake))->assertSessionHasNoErrors();

        $this->assertSame('closed', $stocktake->fresh()->status);
        $this->assertSame(9, $unit->fresh()->qty_on_hand);
        $this->actingAs($supervisor)->get('/warehouse/stocktakes')->assertOk()->assertSee($stocktake->stocktake_no);
        $this->actingAs($this->staff('warehouse_operator'))->post(route('warehouse.stocktakes.scan', $stocktake), ['code' => $unit->label_code])->assertRedirect(); // closed: operator gets an error, not a crash
    }

    public function test_scan_resolves_units_locations_and_marks(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$asn, $line, $unit] = $this->stock($client->id, $warehouse, 3, 'GD20260506BC');
        $operator = $this->staff('warehouse_operator');

        $this->actingAs($operator)->get('/warehouse/scan')->assertOk()->assertSee(__('warehouse.scan.camera'));
        $this->actingAs($operator)->get('/warehouse/scan/resolve?code='.$unit->label_code)->assertRedirect(route('warehouse.stock.show', $unit));
        $this->actingAs($operator)->get('/warehouse/scan/resolve?code=mel-a-01-01')->assertRedirect(route('warehouse.index', ['location' => 'MEL-A-01-01']));
        $this->actingAs($operator)->get('/warehouse/scan/resolve?code=NOPE-123')->assertRedirect('/warehouse/scan')->assertSessionHasErrors('code');

        // A consignment mark on an ASN still receiving opens that ASN.
        $open = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        app(AsnService::class)->addLines($open, [['description' => 'x', 'expected_cartons' => 1, 'consignment_mark' => 'JJ26051603']]);
        $this->actingAs($operator)->get('/warehouse/scan/resolve?code=jj26051603')->assertRedirectContains(route('warehouse.asns.show', $open));
    }

    public function test_labels_render_as_pdf(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$asn, , $unit] = $this->stock($client->id, $warehouse, 3);
        $operator = $this->staff('warehouse_operator');

        $response = $this->actingAs($operator)->get(route('warehouse.labels.asn', $asn));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->actingAs($operator)->get(route('warehouse.labels.units', ['ids' => [$unit->id]]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($operator)->get(route('warehouse.labels.locations', ['warehouse_id' => $warehouse->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_switching_the_working_warehouse_filters_the_lists(): void
    {
        $client = $this->client();
        $mel = $this->warehouse('MEL');
        $syd = $this->warehouse('SYD');
        [, , $melUnit] = $this->stock($client->id, $mel, 5);
        [, , $sydUnit] = $this->stock($client->id, $syd, 7);
        $operator = $this->staff('warehouse_operator');

        $this->actingAs($operator)->post('/warehouse/switch', ['warehouse_id' => $syd->id])->assertRedirect();
        $this->actingAs($operator)->get('/warehouse')->assertOk()->assertSee($sydUnit->label_code)->assertDontSee($melUnit->label_code);
        $this->actingAs($operator)->post('/warehouse/switch', ['warehouse_id' => ''])->assertRedirect();
        $this->actingAs($operator)->get('/warehouse')->assertOk()->assertSee($sydUnit->label_code)->assertSee($melUnit->label_code);
    }

    public function test_move_quarantine_and_restore_from_the_unit_page(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $syd = $this->warehouse('SYD');
        [, , $unit] = $this->stock($client->id, $warehouse, 6);
        $operator = $this->staff('warehouse_operator');

        $this->actingAs($operator)->post(route('warehouse.stock.move', $unit), ['location_code' => 'SYD-A-01-01'])->assertSessionHasNoErrors();
        $this->assertSame($syd->id, $unit->fresh()->warehouse_id);

        $this->actingAs($operator)->post(route('warehouse.stock.quarantine', $unit), ['condition' => 'damaged', 'reason' => 'crushed corner', 'photos' => [UploadedFile::fake()->image('damage.jpg')]])->assertSessionHasNoErrors();
        $this->assertSame('damaged', $unit->fresh()->condition);
        $this->assertSame('SYD-QA-01-01', $unit->fresh()->location->full_code);
        $this->assertDatabaseHas('documents', ['type' => 'photo', 'related_type' => 'stock_unit', 'related_id' => $unit->id]);
        $this->actingAs($operator)->get(route('warehouse.stock.show', $unit))->assertOk()->assertSee('crushed corner')->assertSee(__('warehouse.moves.restore_do'));

        $this->actingAs($operator)->post(route('warehouse.stock.restore', $unit), ['location_code' => 'SYD-A-01-02', 'reason' => 'repacked'])->assertSessionHasNoErrors();
        $this->assertSame('good', $unit->fresh()->condition);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_supervisor_creates_a_warehouse(): void
    {
        $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');

        $this->actingAs($supervisor)->post('/warehouse/config/warehouses', ['code' => 'BNE', 'name' => 'Brisbane DC', 'state' => 'QLD'])->assertRedirect();
        $this->assertDatabaseHas('warehouses', ['code' => 'BNE', 'active' => 1]);
        $this->actingAs($supervisor)->get('/warehouse/config/locations')->assertOk()->assertSee('Brisbane DC');
        $this->actingAs($this->staff('warehouse_operator'))->post('/warehouse/config/warehouses', ['code' => 'ADL', 'name' => 'x'])->assertRedirect(); // operator is in the write group; role check happens in the form only
    }
}
