<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\ScanRecord;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\MoveService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\QuarantineService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\StocktakeService;
use App\Modules\Warehouse\Services\TaskService;
use App\Support\Contracts\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B10b / B14 / B12: stocktake with reasons, moves (incl. cross-warehouse), quarantine (§4.7 #6), serial scans (#24). */
class StocktakeMoveQuarantineTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function stock(int $clientId, array $cartons): array
    {
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $clientId, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => array_sum($cartons)]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => array_sum($cartons), 'units' => array_map(fn ($q) => ['unit_type' => 'carton', 'carton_qty' => $q], $cartons)], $this->location($warehouse, 'receiving'));
        foreach ($units as $u) {
            app(PutawayService::class)->putaway($u, $this->location($warehouse, 'storage'));
        }

        return [$warehouse, $asn, $line, array_map(fn ($u) => $u->fresh(), $units)];
    }

    public function test_stocktake_needs_reasons_for_variances_and_adjusts_through_the_ledger(): void
    {
        $client = $this->client();
        [$warehouse, , $line, $units] = $this->stock($client->id, [10, 8]);
        $this->actingAs($this->staff('warehouse_supervisor'));
        $service = app(StocktakeService::class);

        $stocktake = $service->open(['warehouse_id' => $warehouse->id, 'client_id' => $client->id]);
        $this->assertMatchesRegularExpression('/^STK-\d{8}-0001$/', $stocktake->stocktake_no);
        $this->assertSame(2, $stocktake->lines()->count());

        [$l1, $l2] = $stocktake->lines()->orderBy('id')->get();
        $service->count($l1, 10, scanned: true);
        $service->count($l2, 6);

        try {
            $service->close($stocktake);
            $this->fail('variance without reason must block the close');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $service->count($l2->fresh(), 6, 'two cartons water damaged, disposed');
        $service->close($stocktake->fresh());

        $this->assertSame('closed', $stocktake->fresh()->status);
        $this->assertSame(6, $units[1]->fresh()->qty_on_hand);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $units[1]->id, 'movement_type' => 'adjust', 'qty' => -2, 'qty_before' => 8, 'qty_after' => 6, 'source_type' => 'stocktake', 'source_id' => $stocktake->id]);
        $this->assertDatabaseHas('exceptions', ['type' => 'discrepancy', 'source_type' => 'stocktake', 'source_id' => $stocktake->id]);
        $this->assertSame(['qty_on_hand' => 16, 'qty_reserved' => 0, 'qty_available' => 16], app(StockService::class)->onHand($client->id, $line->id));
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_moves_are_ledgered_and_may_cross_warehouses(): void
    {
        $client = $this->client();
        [$warehouse, , , $units] = $this->stock($client->id, [5]);
        $syd = $this->warehouse('SYD');

        $moved = app(MoveService::class)->move($units[0], $this->location($warehouse, 'pickface'));
        $this->assertSame('pickface', $moved->pallet_class);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $units[0]->id, 'movement_type' => 'transfer', 'to_location_id' => $this->location($warehouse, 'pickface')->id]);

        $moved = app(MoveService::class)->move($moved, $this->location($syd, 'storage'));
        $this->assertSame($syd->id, $moved->warehouse_id); // B14 cross-warehouse move
        $this->assertSame('SYD-A-01-01', $moved->location->full_code);
        $this->assertSame(5, $moved->qty_on_hand);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_quarantined_stock_leaves_available_stock_and_needs_no_reservations(): void
    {
        $client = $this->client();
        [$warehouse, , $line, $units] = $this->stock($client->id, [10, 10]);
        $stock = app(StockService::class);

        $stock->reserve($client->id, 77, [['order_line_id' => 1, 'asn_line_id' => $line->id, 'qty' => 10]]);
        try {
            app(QuarantineService::class)->quarantine($units[0]->fresh(), 'damaged', 'forklift hit');
            $this->fail('reserved stock cannot be quarantined');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('reserved', $e->getMessage());
        }

        $unit = app(QuarantineService::class)->quarantine($units[1]->fresh(), 'damaged', 'water damage', [['path' => 'photos/x.jpg', 'mime' => 'image/jpeg']]);

        $this->assertSame('damaged', $unit->condition);
        $this->assertSame('MEL-QA-01-01', $unit->location->full_code);
        $this->assertSame(['qty_on_hand' => 10, 'qty_reserved' => 10, 'qty_available' => 0], $stock->onHand($client->id, $line->id)); // §4.7 #6
        $this->assertDatabaseHas('documents', ['type' => 'photo', 'related_type' => 'stock_unit', 'related_id' => $unit->id]);
        $this->assertDatabaseHas('exceptions', ['source_type' => 'stock_unit', 'source_id' => $unit->id]);

        $this->expectExceptionMessage('quarantine');
        app(MoveService::class)->move($unit, $this->location($warehouse, 'storage'));
    }

    public function test_restore_from_quarantine_makes_stock_available_again(): void
    {
        $client = $this->client();
        [$warehouse, , $line, $units] = $this->stock($client->id, [4]);
        $unit = app(QuarantineService::class)->quarantine($units[0], 'quarantine', 'awaiting inspection');
        $this->assertSame(0, app(StockService::class)->onHand($client->id, $line->id)['qty_available']);

        app(QuarantineService::class)->restore($unit, $this->location($warehouse, 'storage'), 'inspected ok');
        $this->assertSame(4, app(StockService::class)->onHand($client->id, $line->id)['qty_available']);
    }

    public function test_scanning_task_stores_serial_numbers_and_bills_per_scan(): void
    {
        $client = $this->client();
        [$warehouse, $asn] = $this->stock($client->id, [5]);
        $tasks = app(TaskService::class);
        $task = $tasks->create('scanning', ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'asn', 'source_id' => $asn->id, 'asn_id' => $asn->id]);

        $tasks->complete($task, ['serials' => ['SN-001', 'SN-002', 'SN-003', 'SN-004', 'SN-005', 'SN-005 ', '']]);

        $this->assertSame(5, $task->fresh()->scan_records ?? ScanRecord::query()->where('task_id', $task->id)->count());
        $this->assertDatabaseHas('warehouse_tasks', ['id' => $task->id, 'status' => 'done', 'billable_qty' => 5, 'billable_uom' => 'scan']);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'task.completed']);
        $this->assertSame(5, OutboxEvent::query()->where('event_name', 'task.completed')->firstOrFail()->payload['scan_count']); // §4.7 #24
    }
}
