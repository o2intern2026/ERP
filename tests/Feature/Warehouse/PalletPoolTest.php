<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Services\StorageBillingService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\SnapshotService;
use App\Modules\Warehouse\Services\StockLedger;
use App\Support\Exceptions\RuleViolation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #169 托盘管理 + #170 receiving defaults: our wooden pallet with its preset footprint / tare is the default, an emptied
 * pallet is cleared into the free pool and reused before a new number is printed, units move between pallets, pallets can be corrected.
 */
class PalletPoolTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** @return array{0: Asn, 1: list<AsnLine>} */
    private function asn(Client $client, Warehouse $warehouse, array $cartons): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $lines = app(AsnService::class)->addLines($asn, array_map(fn (int $n, int $i) => ['description' => 'Goods '.$i, 'consignment_mark' => 'PP'.$i, 'expected_cartons' => $n], $cartons, array_keys($cartons)));

        return [$asn->fresh(), array_values($lines)];
    }

    public function test_receiving_defaults_to_a_warehouse_pallet_with_its_preset_and_the_form_prefills_the_free_pallet_number(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$asn, [$a, $b]] = $this->asn($client, $warehouse, [10, 6]);
        $rcv = $this->location($warehouse, 'receiving');

        // No source, no dims typed: our wooden pallet, 1165 × 1165 × 150 mm, 30 kg, a standard class; the unit carries the same copies.
        [$ua] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10]]], $rcv);
        $pallet = $ua->fresh()->pallet;
        $this->assertSame(['warehouse_plain', 1165, 1165, 150, 30.0, 'standard', 0], [$pallet->pallet_source, $pallet->length_mm, $pallet->width_mm, $pallet->height_mm, (float) $pallet->weight_kg, $pallet->pallet_class, $pallet->reuse_count]);
        $this->assertSame(['warehouse_plain', 1165, 30.0], [$ua->fresh()->pallet_source, $ua->fresh()->length_mm, (float) $ua->fresh()->weight_kg]);
        // The whole-ASN form's rows default to our wooden pallet too.
        app(GoodsReceiptService::class)->receiveLines($asn, [['asn_line_id' => $b->id, 'received_cartons' => 6, 'unit_type' => 'pallet']], $rcv, $supervisor->id);
        $this->assertSame('warehouse_plain', StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $b->id)->sole()->pallet->pallet_source);

        // The per-line form: source pre-selected, the presets embedded for the script, and — once a pallet is free — its number offered first.
        [$asn2, [$c]] = $this->asn($client, $warehouse, [4]);
        $page = $this->get(route('warehouse.receiving.form', [$asn2, $c]))->assertOk()->assertSee('"warehouse_plain":{"length_mm":1165', false);
        $this->assertMatchesRegularExpression('/<option value="warehouse_plain" selected>/', $page->getContent());
        $this->assertStringContainsString('const freePallets = [];', $page->getContent());
        app(StockLedger::class)->record($ua->fresh(), 'adjust', -10, ['source_type' => 'test', 'source_id' => null]);
        $pallet->refreshStatus();
        $this->post(route('warehouse.pallets.release', $pallet))->assertSessionHasNoErrors();
        $this->get(route('warehouse.receiving.form', [$asn2, $c]))->assertOk()->assertSee('const freePallets = ["'.$pallet->pallet_no.'"];', false);
    }

    public function test_an_emptied_pallet_is_cleared_into_the_free_pool_and_reused_before_a_new_number_without_merging_the_jobs_storage_week(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $clientA = $this->client();
        $clientB = $this->client(['code' => 'POOLB', 'name' => 'Pool B Pty Ltd']);
        $warehouse = $this->warehouse();
        $rcv = $this->location($warehouse, 'receiving');
        $storage = $this->location($warehouse, 'storage');
        [, [$a]] = $this->asn($clientA, $warehouse, [10]);
        [$ua] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10]]], $rcv);
        $pallet = $ua->fresh()->pallet;
        app(PutawayService::class)->putaway($ua->fresh(), $storage);
        app(SnapshotService::class)->take(Carbon::parse('2026-09-07')); // Monday: client A's goods on the pallet

        // Still loaded → cannot be cleared. Picked empty → 已空, still in its slot (the map still shows it there) → 清走 → free, no slot, no units, no client.
        $this->post(route('warehouse.pallets.release', $pallet))->assertSessionHasErrors('release');
        app(StockLedger::class)->record($ua->fresh(), 'adjust', -10, ['source_type' => 'test', 'source_id' => null]);
        $pallet->refreshStatus();
        $this->assertSame(['empty', $storage->id], [$pallet->fresh()->status, $pallet->fresh()->location_id]);
        $this->actingAs($this->staff('warehouse_operator'))->post(route('warehouse.pallets.release', $pallet))->assertSessionHasNoErrors();
        $this->assertSame([true, null, null], [$pallet->fresh()->isFree(), $pallet->fresh()->location_id, $ua->fresh()->pallet_id]);
        $this->assertNotNull($pallet->fresh()->released_at);
        $this->actingAs($supervisor)->get(route('warehouse.pallets.index', ['free' => 1]))->assertOk()->assertSee($pallet->pallet_no)->assertSee(__('warehouse.pallets.free_count', ['count' => 1]));

        // Client B's goods take the free pallet (same number, new owner, at the dock), the next pallet gets a new number.
        [$asnB, [$b1, $b2]] = $this->asn($clientB, $warehouse, [8, 5]);
        [$ub1] = app(ReceivingService::class)->receiveLine($b1, ['received_cartons' => 8, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 8, 'height_mm' => 900, 'weight_kg' => 240]]], $rcv);
        $reused = $ub1->fresh()->pallet;
        $this->assertSame([$pallet->id, $pallet->pallet_no, $clientB->id, $asnB->job_id, $rcv->id, 'in_use', 1, 1165, 900, 240.0, null], [$reused->id, $reused->pallet_no, $reused->client_id, $reused->job_id, $reused->location_id, $reused->status, $reused->reuse_count, $reused->length_mm, $reused->height_mm, (float) $reused->weight_kg, $reused->released_at]);
        [$ub2] = app(ReceivingService::class)->receiveLine($b2, ['received_cartons' => 5, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 5]]], $rcv);
        $this->assertNotSame($pallet->id, $ub2->fresh()->pallet_id, 'the pool is empty again → a fresh number');
        $this->assertSame(2, Pallet::query()->count());
        app(PutawayService::class)->putaway($ub1->fresh(), $storage);
        app(SnapshotService::class)->take(Carbon::parse('2026-09-09')); // Wednesday, same ISO week: client B's goods on the same pallet id

        // Weekly storage: one line for client A's Job (Monday) and one for client B's (Wednesday) — the reused pallet id never merges them.
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-08'));
        $lines = Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->where('source_type', 'snapshot')->get();
        $this->assertEqualsCanonicalizing([$ua->job_id, $ub1->job_id, $ub2->job_id], $lines->pluck('job_id')->map(fn ($j) => (int) $j)->all(), 'A\'s week on the pallet, B\'s week on the reused pallet, B\'s second pallet');
    }

    public function test_units_move_between_pallets_or_off_and_the_supervisor_corrects_pallet_details(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client();
        $warehouse = $this->warehouse();
        $rcv = $this->location($warehouse, 'receiving');
        [, [$a, $b, $c]] = $this->asn($client, $warehouse, [10, 6, 4]);
        [$ua] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10]]], $rcv);
        $p1 = $ua->fresh()->pallet;
        [$ub] = app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 6, 'units' => [['unit_type' => 'carton', 'carton_qty' => 6, 'pallet_no' => $p1->pallet_no]]], $rcv);
        [$uc] = app(ReceivingService::class)->receiveLine($c, ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4]]], $rcv);
        $p2 = $uc->fresh()->pallet;
        $slot1 = $this->location($warehouse, 'storage');
        $slot2 = Location::query()->where('full_code', 'MEL-A-01-02')->sole();
        app(PutawayService::class)->putaway($ua->fresh(), $slot1);
        app(PutawayService::class)->putaway($uc->fresh(), $slot2);

        // B joins pallet 2 (and its slot); then comes off it as a loose unit that stays where it is; another client's pallet is refused.
        $this->post(route('warehouse.pallets.repalletise', $p1), ['unit_code' => $ub->label_code, 'target' => strtolower($p2->pallet_no)])->assertSessionHasNoErrors();
        $this->assertSame([$p2->id, $slot2->id, 'in_use', 'in_use'], [$ub->fresh()->pallet_id, $ub->fresh()->location_id, $p1->fresh()->status, $p2->fresh()->status]);
        $this->post(route('warehouse.pallets.repalletise', $p2), ['unit_code' => 'U'.$ub->id, 'target' => ''])->assertSessionHasNoErrors();
        $this->assertSame([null, $slot2->id], [$ub->fresh()->pallet_id, $ub->fresh()->location_id]);
        $this->post(route('warehouse.pallets.repalletise', $p1), ['unit_code' => $ub->label_code, 'target' => $p2->pallet_no])->assertSessionHasErrors('repalletise'); // B is no longer on pallet 1
        $other = $this->client(['code' => 'POOLX', 'name' => 'Other X']);
        [, [$x]] = $this->asn($other, $warehouse, [3]);
        [$ux] = app(ReceivingService::class)->receiveLine($x, ['received_cartons' => 3, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 3]]], $rcv);
        $this->post(route('warehouse.pallets.repalletise', $p1), ['unit_code' => $ua->label_code, 'target' => $ux->fresh()->pallet->pallet_no])->assertSessionHasErrors('repalletise');

        // The pallet page and the correction form (supervisor only): source / class / dims flow to the pallet and its pallet-type unit.
        $page = $this->get(route('warehouse.pallets.show', $p1))->assertOk()->assertSee($ua->label_code)->assertSee('id="pallet-edit"', false)->assertSee(__('warehouse.pallets.repalletise'));
        $this->assertDoesNotMatchRegularExpression('/warehouse\.pallets\./', $page->getContent());
        $this->post(route('warehouse.pallets.update', $p1), ['pallet_source' => 'chep', 'pallet_class' => 'oversize_high', 'pallet_class_overridden_reason' => 'tall load', 'length_mm' => 1165, 'width_mm' => 1165, 'height_mm' => 1900, 'weight_kg' => 410])->assertSessionHasNoErrors();
        $this->assertSame(['chep', 'oversize_high', 1900, 'chep', 'oversize_high'], [$p1->fresh()->pallet_source, $p1->fresh()->pallet_class, $p1->fresh()->height_mm, $ua->fresh()->pallet_source, $ua->fresh()->pallet_class]);
        $this->actingAs($this->staff('warehouse_operator'))->post(route('warehouse.pallets.update', $p1), ['pallet_source' => 'loscam'])->assertForbidden();
        $this->actingAs($this->clientUser($client))->get(route('warehouse.pallets.index'))->assertForbidden();
    }

    public function test_the_box_suggests_a_free_pallet_or_the_next_new_number_which_is_issued_on_submit_and_a_taken_suggestion_falls_through(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client();
        $warehouse = $this->warehouse();
        $rcv = $this->location($warehouse, 'receiving');
        [$asn, [$a, $b, $c, $d]] = $this->asn($client, $warehouse, [4, 4, 4, 4]);

        // No pallet exists yet: the form offers P-000001 as the next new number (nothing free); the bulk form carries the same list.
        $form = $this->get(route('warehouse.receiving.form', [$asn, $a]))->assertOk();
        $this->assertStringContainsString('const freePallets = [];', $form->getContent());
        $this->assertStringContainsString('const nextNumbers = ["P-000001","P-000002"', $form->getContent());
        $this->assertStringContainsString('name="units[0][pallet_no_auto]"', $form->getContent());
        $bulk = $this->get(route('warehouse.receiving.bulk_form', $asn))->assertOk();
        $this->assertStringContainsString('const suggestions = ["P-000001","P-000002"', $bulk->getContent());
        $this->assertStringContainsString('name="rows[0][pallet_no_auto]"', $bulk->getContent());

        // Submitting the suggested number issues it; a pre-printed label typed by hand is issued too; a non-pallet code is refused.
        [$ua] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4, 'pallet_no' => 'P-000001', 'pallet_no_auto' => 1]]], $rcv);
        $this->assertSame('P-000001', $ua->fresh()->pallet->pallet_no);
        [$ub] = app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4, 'pallet_no' => 'p-000777']]], $rcv);
        $this->assertSame('P-000777', $ub->fresh()->pallet->pallet_no, 'a typed new number in the pallet pattern is issued as it is');
        try {
            app(ReceivingService::class)->receiveLine($c, ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4, 'pallet_no' => 'X-1']]], $rcv);
            $this->fail('an unknown non-pallet code was accepted');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.receiving.errors.pallet_unusable', $e->langKey());
        }

        // Another client's dock got P-000778 first: our suggested P-000778 is taken (in use elsewhere) → the next new number, no refusal.
        $other = $this->client(['code' => 'POOLZ', 'name' => 'Pool Z']);
        [, [$z]] = $this->asn($other, $warehouse, [2]);
        app(ReceivingService::class)->receiveLine($z, ['received_cartons' => 2, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 2, 'pallet_no' => 'P-000778', 'pallet_no_auto' => 1]]], $rcv);
        [$uc] = app(ReceivingService::class)->receiveLine($c, ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4, 'pallet_no' => 'P-000778', 'pallet_no_auto' => 1]]], $rcv);
        $this->assertSame('P-000779', $uc->fresh()->pallet->pallet_no);
        // The same number typed by hand (no auto flag) is a refusal, as before.
        try {
            app(ReceivingService::class)->receiveLine($d, ['received_cartons' => 4, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 4, 'pallet_no' => 'P-000778']]], $rcv);
            $this->fail('another client\'s pallet was accepted');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.receiving.errors.pallet_unusable', $e->langKey());
        }
        // Bulk rows: the suggested number flows through the packed row too.
        app(GoodsReceiptService::class)->receiveLines($asn, [['asn_line_id' => $d->id, 'received_cartons' => 4, 'unit_type' => 'pallet', 'unit_count' => 1, 'pallet_no' => 'P-000780', 'pallet_no_auto' => 1]], $rcv, $supervisor->id);
        $this->assertSame('P-000780', StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $d->id)->sole()->pallet->pallet_no);
    }
}
