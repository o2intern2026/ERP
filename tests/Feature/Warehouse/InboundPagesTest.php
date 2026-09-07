<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnImport;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** The B2 / B2b pages: create ASN with container, import a manifest, receive a line, put away by scanned location code, stock query. */
class InboundPagesTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_operator_creates_a_container_asn_from_the_form(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');

        $this->actingAs($operator)->get('/warehouse/asns/create')->assertOk();
        $response = $this->actingAs($operator)->post('/warehouse/asns', [
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'expected_date' => '2026-09-20', 'reference' => 'COSU1',
            'containers' => [['container_no' => 'COSU1', 'size' => '40', 'unpack_mode' => 'pallet', 'gross_weight_kg' => '15000'], ['container_no' => '', 'size' => '40', 'unpack_mode' => 'loose']],
        ]);

        $asn = Asn::query()->firstOrFail();
        $response->assertRedirect(route('warehouse.asns.show', $asn));
        $this->assertSame(1, $asn->containers()->count());
        $this->actingAs($operator)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee($asn->asn_no)->assertSee('COSU1');
        $this->actingAs($operator)->get('/warehouse/asns')->assertOk()->assertSee($asn->asn_no);
    }

    public function test_import_creates_lines_from_the_real_manifest_parser_and_records_the_import(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'IMP1', 'size' => '40', 'unpack_mode' => 'loose']]]);
        $workbook = base_path('data/需派送货物清单.xlsx'); // the real client list, parsed by X1's SpreadsheetManifestParser (A4, M3)

        $this->actingAs($this->staff('customer_service'))
            ->post(route('warehouse.asns.import', $asn), ['file' => new UploadedFile($workbook, '需派送货物清单.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true), 'container_no' => 'IMP1'])
            ->assertRedirect(route('warehouse.asns.show', $asn));

        $import = AsnImport::query()->firstOrFail();
        $this->assertSame(135, $import->row_count);
        $this->assertSame(135, $asn->lines()->count());
        $this->assertSame(135, $asn->containers()->first()->fresh()->line_count);
        $this->assertSame(10, $import->error_count); // the workbook's five incomplete rows are reported, not silently dropped
        $this->assertSame('failed', $import->status);
        $this->assertTrue($asn->lines()->whereNotNull('consignment_mark')->where('expected_cartons', '>', 0)->exists());
        $this->assertDatabaseHas('documents', ['id' => $import->document_id, 'type' => 'packing_list', 'related_type' => 'asn', 'related_id' => $asn->id]);
    }

    public function test_receive_then_put_away_by_scanned_location_code_then_query_stock(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Chairs', 'expected_cartons' => 12, 'consignment_mark' => 'CHAIR-1']]);

        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertOk()->assertSee('Chairs');
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), [
            'receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'received_cartons' => 10, 'damaged_cartons' => 0, 'variance_reason' => 'short shipped', 'unloaded_pallets' => 2,
            'units' => [['unit_type' => 'pallet', 'carton_qty' => 10, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1000, 'weight_kg' => 300, 'pallet_source' => 'client_own']],
        ])->assertRedirect(route('warehouse.asns.show', $asn));

        $unit = StockUnit::query()->firstOrFail();
        $this->assertSame(10, $unit->qty_on_hand);
        $this->assertDatabaseHas('warehouse_tasks', ['asn_id' => $asn->id, 'task_type' => 'receiving', 'status' => 'done', 'billable_qty' => 2, 'billable_uom' => 'pallet']); // §4.7 #23 unload
        $this->assertDatabaseHas('exceptions', ['type' => 'discrepancy', 'source_id' => $line->id]);

        $this->actingAs($operator)->get('/warehouse/putaway')->assertOk()->assertSee($unit->label_code);
        $this->actingAs($operator)->post(route('warehouse.putaway.store', $unit), ['location_code' => 'MEL-A-01-02'])->assertSessionHasNoErrors();
        $this->assertTrue($unit->fresh()->putaway_completed);
        $this->assertSame('MEL-A-01-02', $unit->fresh()->location->full_code);

        $this->actingAs($operator)->post(route('warehouse.putaway.store', $unit), ['location_code' => 'MEL-ZZ-99-99'])->assertSessionHasErrors('location_code');

        $this->actingAs($this->staff('customer_service'))->get('/warehouse?consignment_mark=CHAIR')->assertOk()->assertSee($unit->label_code)->assertSee('MEL-A-01-02');
        $this->actingAs($operator)->get(route('warehouse.stock.show', $unit))->assertOk()->assertSee(__('warehouse.movement_types.receipt'))->assertSee(__('warehouse.movement_types.putaway'));
        $this->actingAs($this->staff('transport_operator'))->get('/warehouse')->assertForbidden();
    }

    public function test_tasks_page_creates_and_completes_a_devanning_task(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'TSK1', 'size' => '20', 'unpack_mode' => 'pallet']]]);
        $container = $asn->containers()->first();

        $this->actingAs($supervisor)->get('/warehouse/tasks/create?asn_id='.$asn->id)->assertOk();
        $this->actingAs($supervisor)->post('/warehouse/tasks', ['asn_id' => $asn->id, 'container_id' => $container->id, 'task_type' => 'devanning'])->assertRedirect('/warehouse/tasks');
        $task = $asn->hasMany(WarehouseTask::class)->firstOrFail();
        $this->actingAs($supervisor)->post(route('warehouse.tasks.complete', $task))->assertRedirect();

        $this->assertDatabaseHas('warehouse_tasks', ['id' => $task->id, 'status' => 'done', 'billable_qty' => 1, 'billable_uom' => 'container']);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'task.completed', 'job_id' => $asn->job_id]);
        $this->actingAs($supervisor)->get('/warehouse/tasks')->assertOk()->assertSee($task->task_no);
        $this->actingAs($supervisor)->get('/warehouse/config/locations')->assertOk()->assertSee('MEL-A-01-01');
    }
}
