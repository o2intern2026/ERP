<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Services\StorageBillingService;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A6b: one storage charge per unit per week from daily snapshots; pallet rental by source; pickface by slot (§6.8 #4 #13, §4.7 #20 #21). */
class StorageBillingTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_a_week_of_snapshots_bills_each_unit_once_by_class_source_and_pickface_slot(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'STO1', 'size' => '40', 'unpack_mode' => 'pallet']]]);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => 90]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 90, 'units' => [
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'chep'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'chep'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1700, 'weight_kg' => 400, 'pallet_source' => 'warehouse_plain'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 300, 'pallet_source' => 'client_own'],
            ['unit_type' => 'carton', 'carton_qty' => 10],
        ]], $this->location($warehouse, 'receiving'));
        app(PutawayService::class)->putaway($units[0], $this->location($warehouse, 'storage'));
        app(PutawayService::class)->putaway($units[1], $this->location($warehouse, 'storage'));
        app(PutawayService::class)->putaway($units[2], $this->location($warehouse, 'storage'));
        app(PutawayService::class)->putaway($units[3], $this->location($warehouse, 'pickface'));
        app(PutawayService::class)->putaway($units[4], $this->location($warehouse, 'pickface'));

        // Three snapshots in ISO week 2026-W37 (Mon 7 Sep – Sun 13 Sep) — still one charge per unit for the week.
        foreach (['2026-09-08', '2026-09-09', '2026-09-11'] as $day) {
            app(SnapshotService::class)->take(Carbon::parse($day));
        }

        $charges = app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-10'));
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-13')); // re-run: idempotent

        $byCode = Charge::query()->with('chargeCode')->get()->groupBy(fn (Charge $c) => $c->chargeCode->code)->map(fn ($g) => ['n' => $g->count(), 'qty' => (float) $g->sum('qty'), 'amount' => (int) $g->sum('amount_cents')]);
        $this->assertEquals(['n' => 2, 'qty' => 2.0, 'amount' => 900], $byCode['WH-STORAGE-PLT-WK']);        // two standard CHEP pallets × 4.50
        $this->assertEquals(['n' => 1, 'qty' => 1.0, 'amount' => 800], $byCode['WH-STORAGE-PLT-HIGH-WK']);  // the 1700 mm pallet
        $this->assertEquals(['n' => 2, 'qty' => 2.0, 'amount' => 400], $byCode['WH-PALLET-RENT-POOL-WK']);  // CHEP rental (§6.8 #13)
        $this->assertEquals(['n' => 1, 'qty' => 1.0, 'amount' => 70], $byCode['WH-PALLET-RENT-PLAIN-WK']);  // warehouse plain pallet
        $this->assertEquals(['n' => 1, 'qty' => 1.0, 'amount' => 650], $byCode['WH-STORAGE-PICKFACE-WK']);  // two units share one slot → 1 slot (§4.7 #21)
        $this->assertArrayNotHasKey('WH-STORAGE-CTN-WK', $byCode->all()); // loose cartons in the pickface slot are covered by the slot fee
        $this->assertSame(7, Charge::query()->count());
        $this->assertStringEndsWith(':week:2026-W37', Charge::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->value('source_activity_id'));
        $this->assertSame('2026-09-13', Charge::query()->first()->charge_date->toDateString());
        $this->assertCount(7, $charges);

        // Client-own pallets never produce rental; a following week bills again (the unit is still there).
        app(SnapshotService::class)->take(Carbon::parse('2026-09-15'));
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-15'));
        $this->assertSame(14, Charge::query()->count());
        $this->artisan('billing:storage-weekly --week=2026-09-15')->assertSuccessful();
        $this->assertSame(14, Charge::query()->count());
    }
}
