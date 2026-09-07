<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\StockService;
use App\Support\Contracts\StockService as StockServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** B1 / B4a: ledger as truth, availability after putaway, FIFO reservation with locks, release, client isolation, reconcile. */
class StockCoreTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** Receive $cartons for a fresh client on a loose-truck ASN and put them away as carton units of $perUnit. */
    private function stockFor(int $clientId, array $unitCartons): array
    {
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $clientId, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Widgets', 'expected_cartons' => array_sum($unitCartons), 'consignment_mark' => 'MARK-'.$clientId]]);
        $units = app(ReceivingService::class)->receiveLine($line, [
            'received_cartons' => array_sum($unitCartons),
            'units' => array_map(fn ($q) => ['unit_type' => 'carton', 'carton_qty' => $q], $unitCartons),
        ], $this->location($warehouse, 'receiving'));

        return [$asn, $line, $units, $warehouse];
    }

    public function test_real_stock_service_is_bound(): void
    {
        $this->assertInstanceOf(StockService::class, app(StockServiceContract::class));
    }

    public function test_stock_is_available_only_after_putaway_and_balances_follow_the_ledger(): void
    {
        $client = $this->client();
        [, $line, $units, $warehouse] = $this->stockFor($client->id, [10, 5]);
        $stock = app(StockServiceContract::class);

        $this->assertSame(['qty_on_hand' => 0, 'qty_reserved' => 0, 'qty_available' => 0], $stock->onHand($client->id, $line->id));
        $this->assertSame(10, $units[0]->fresh()->qty_on_hand);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $units[0]->id, 'movement_type' => 'receipt', 'qty' => 10, 'qty_before' => 0, 'qty_after' => 10]);

        foreach ($units as $unit) {
            app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));
        }

        $this->assertSame(['qty_on_hand' => 15, 'qty_reserved' => 0, 'qty_available' => 15], $stock->onHand($client->id, $line->id));
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $units[0]->id, 'movement_type' => 'putaway', 'qty' => 0]);
        $this->artisan('stock:reconcile')->assertSuccessful(); // §4.7 #2
    }

    public function test_reservation_is_fifo_partial_and_released_with_ledger_rows(): void
    {
        $client = $this->client();
        [, $line, $units, $warehouse] = $this->stockFor($client->id, [10, 5]);
        foreach ($units as $u) {
            app(PutawayService::class)->putaway($u, $this->location($warehouse, 'storage'));
        }
        $stock = app(StockServiceContract::class);

        [$result] = $stock->reserve($client->id, 901, [['order_line_id' => 1, 'asn_line_id' => $line->id, 'qty' => 12]]);
        $this->assertSame(12, $result['reserved_qty']);
        $this->assertSame(0, $result['shortfall_qty']);
        $this->assertCount(2, $result['reservation_ids']); // 10 from the first (oldest) unit + 2 from the second
        $this->assertSame(10, $units[0]->fresh()->qty_reserved);
        $this->assertSame(2, $units[1]->fresh()->qty_reserved);
        $this->assertSame(['qty_on_hand' => 15, 'qty_reserved' => 12, 'qty_available' => 3], $stock->onHand($client->id, $line->id));

        [$short] = $stock->reserve($client->id, 902, [['order_line_id' => 7, 'asn_line_id' => $line->id, 'qty' => 5]]);
        $this->assertSame(3, $short['reserved_qty']);
        $this->assertSame(2, $short['shortfall_qty']);

        $this->assertSame(12, $stock->release(901));
        $this->assertSame(0, $units[0]->fresh()->qty_reserved);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $units[0]->id, 'movement_type' => 'release', 'qty' => -10, 'qty_before' => 10, 'qty_after' => 0]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => 901, 'status' => 'released', 'released_reason' => 'order_cancelled']);
        $this->assertSame(['qty_on_hand' => 15, 'qty_reserved' => 3, 'qty_available' => 12], $stock->onHand($client->id, $line->id));
        $this->artisan('stock:reconcile')->assertSuccessful();
    }

    public function test_client_a_stock_is_never_reserved_for_client_b(): void
    {
        $a = $this->client();
        $b = $this->client();
        [, $lineA, $units, $warehouse] = $this->stockFor($a->id, [20]);
        app(PutawayService::class)->putaway($units[0], $this->location($warehouse, 'storage'));

        [$result] = app(StockServiceContract::class)->reserve($b->id, 903, [['order_line_id' => 1, 'asn_line_id' => $lineA->id, 'qty' => 5]]);

        $this->assertSame(0, $result['reserved_qty']);
        $this->assertSame(5, $result['shortfall_qty']); // §4.7 #3
        $this->assertSame(0, $units[0]->fresh()->qty_reserved);
    }

    public function test_damaged_stock_is_quarantined_and_never_available(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Glass', 'expected_cartons' => 10]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 8, 'damaged_cartons' => 2, 'variance_reason' => 'crushed', 'units' => [['unit_type' => 'carton', 'carton_qty' => 8]]], $this->location($warehouse, 'receiving'));

        $damaged = collect($units)->firstWhere('condition', 'damaged');
        $this->assertSame(2, $damaged->qty_on_hand);
        $this->assertDatabaseHas('exceptions', ['type' => 'discrepancy', 'source_module' => 'warehouse', 'source_type' => 'asn_line', 'source_id' => $line->id]);

        $this->expectExceptionMessage('quarantine');
        app(PutawayService::class)->putaway($damaged, $this->location($warehouse, 'storage'));
    }

    public function test_reconcile_detects_and_fixes_a_drifted_balance(): void
    {
        $client = $this->client();
        [, , $units] = $this->stockFor($client->id, [10]);
        StockUnit::query()->whereKey($units[0]->id)->update(['qty_on_hand' => 99]);

        $this->artisan('stock:reconcile')->assertFailed();
        $this->artisan('stock:reconcile --fix')->assertSuccessful();
        $this->assertSame(10, $units[0]->fresh()->qty_on_hand);
    }

    public function test_putaway_validates_the_location(): void
    {
        $client = $this->client();
        [, , $units, $warehouse] = $this->stockFor($client->id, [4]);
        $other = $this->warehouse('SYD');

        $this->expectExceptionMessage('not a valid putaway target');
        app(PutawayService::class)->putaway($units[0], Location::query()->where('warehouse_id', $other->id)->where('type', 'storage')->firstOrFail());
    }
}
