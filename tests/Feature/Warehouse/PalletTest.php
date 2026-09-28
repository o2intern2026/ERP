<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Services\StorageBillingService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\GoodsReceiptLine;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockLedgerEntry;
use App\Modules\Warehouse\Models\StockSnapshot;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\MoveService;
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
 * CHANGE_REQUESTS #166 托盘牌号 (LPN): a pallet is its own record — goods lines of one client + Job stack on it (mixed pallet), putaway
 * and moves take the whole pallet, the last pick empties it, weekly storage and pallet rental are billed once per pallet.
 */
class PalletTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** @return array{0: Asn, 1: list<AsnLine>} */
    private function asn(Client $client, Warehouse $warehouse, array $cartons): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $lines = app(AsnService::class)->addLines($asn, array_map(fn (int $n, int $i) => ['description' => 'Goods '.$i, 'consignment_mark' => 'PLT'.$i, 'expected_cartons' => $n], $cartons, array_keys($cartons)));

        return [$asn->fresh(), array_values($lines)];
    }

    /** Line A as a new pallet (10 cartons, CHEP), line B's 6 cartons stacked on it. @return array{Pallet, StockUnit, StockUnit, \App\Modules\Warehouse\Models\Asn} */
    private function mixedPallet(Client $client, Warehouse $warehouse): array
    {
        [$asn, [$a, $b]] = $this->asn($client, $warehouse, [10, 6]);
        $rcv = $this->location($warehouse, 'receiving');
        [$ua] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1200, 'weight_kg' => 300, 'pallet_source' => 'chep']]], $rcv);
        $pallet = $ua->fresh()->pallet;
        [$ub] = app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 6, 'units' => [['unit_type' => 'carton', 'carton_qty' => 6, 'pallet_no' => strtolower($pallet->pallet_no)]]], $rcv);

        return [$pallet->fresh(), $ua->fresh(), $ub->fresh(), $asn];
    }

    public function test_receiving_creates_a_pallet_stacks_another_line_on_it_and_refuses_a_pallet_of_another_client_or_one_already_put_away(): void
    {
        $this->actingAs($this->staff('warehouse_supervisor'));
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$pallet, $ua, $ub, $asn] = $this->mixedPallet($client, $warehouse);

        $this->assertMatchesRegularExpression('/^P-\d{6}$/', $pallet->pallet_no);
        $this->assertSame([$client->id, $asn->job_id, $warehouse->id, $this->location($warehouse, 'receiving')->id, 'chep', 1200, 'in_use', false],
            [$pallet->client_id, $pallet->job_id, $pallet->warehouse_id, $pallet->location_id, $pallet->pallet_source, $pallet->length_mm, $pallet->status, $pallet->putaway_completed]);
        $this->assertSame($pallet->id, $ub->pallet_id, 'the carton unit sits on the pallet named by pallet_no (case-insensitive)');
        $this->assertSame([2, 16], [$pallet->units()->count(), (int) $pallet->units()->sum('qty_on_hand')]);
        // The 入库单 counts the pallet once — under the line that brought it, not under the line stacked on it.
        $this->assertSame([1, 0], [(int) GoodsReceiptLine::query()->where('asn_line_id', $ua->asn_line_id)->value('pallet_count'), (int) GoodsReceiptLine::query()->where('asn_line_id', $ub->asn_line_id)->value('pallet_count')]);

        // Another client's goods may not join it; nor may goods join a pallet that is already put away.
        $other = $this->client(['code' => 'PLT2', 'name' => 'Pallet Two Pty Ltd']);
        [, [$oc]] = $this->asn($other, $warehouse, [4]);
        try {
            app(ReceivingService::class)->receiveLine($oc, ['received_cartons' => 4, 'units' => [['unit_type' => 'carton', 'carton_qty' => 4, 'pallet_no' => $pallet->pallet_no]]], $this->location($warehouse, 'receiving'));
            $this->fail('another client stacked goods on the pallet');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.receiving.errors.pallet_unusable', $e->langKey());
        }
        $this->assertSame(0, StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $oc->id)->count(), 'nothing was written');
        app(PutawayService::class)->putaway($ua, $this->location($warehouse, 'storage'));
        [, [$c]] = $this->asn($client, $warehouse, [3]);
        try {
            app(ReceivingService::class)->receiveLine($c, ['received_cartons' => 3, 'units' => [['unit_type' => 'carton', 'carton_qty' => 3, 'pallet_no' => 'P'.$pallet->id]]], $this->location($warehouse, 'receiving'));
            $this->fail('goods joined a put-away pallet');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.receiving.errors.pallet_unusable', $e->langKey());
        }

        // The receiving forms label the box 托盘号 (a raw key showed until the fix): the per-line form and the whole-ASN form.
        [$asn2, [$d]] = $this->asn($client, $warehouse, [2]);
        $form = $this->get(route('warehouse.receiving.form', [$asn2, $d]))->assertOk()->assertSee(__('warehouse.receiving.pallet_no'))->assertSee('[pallet_no]', false);
        $this->assertDoesNotMatchRegularExpression('/warehouse\.receiving\./', $form->getContent());
        $bulk = $this->get(route('warehouse.receiving.bulk_form', $asn2))->assertOk()->assertSee(__('warehouse.receiving.pallet_no'))->assertSee('[pallet_no]', false);
        $this->assertDoesNotMatchRegularExpression('/warehouse\.receiving\./', $bulk->getContent());
        // The pallet label (P<id> barcode) prints in English; the token and the printed number both resolve on the scan page.
        $this->get(route('warehouse.labels.pallets', ['ids' => [$pallet->id]]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/warehouse/scan/resolve?code=p'.$pallet->id)->assertRedirect(route('warehouse.index', ['pallet' => $pallet->pallet_no]));
        $this->assertSame($pallet->id, Pallet::query()->scanCode(strtolower($pallet->pallet_no))->sole()->id);
        // Stock list: the pallet column and the pallet filter; the unit page names the pallet and its mates.
        $this->get(route('warehouse.index', ['pallet' => $pallet->pallet_no]))->assertOk()->assertSee($ua->label_code)->assertSee($ub->label_code)->assertSee('<code>'.$pallet->pallet_no.'</code>', false);
        $this->get(route('warehouse.stock.show', $ua))->assertOk()->assertSee(__('warehouse.stock.pallet_mates'))->assertSee($ub->label_code)->assertSee(__('warehouse.pallet_statuses.in_use'));
    }

    public function test_putaway_and_moves_take_the_whole_pallet_the_last_pick_empties_it_and_storage_bills_the_pallet_once(): void
    {
        $this->actingAs($this->staff('warehouse_supervisor'));
        $client = $this->client();
        $warehouse = $this->warehouse();
        [$pallet, $ua, $ub] = $this->mixedPallet($client, $warehouse);
        $storage = $this->location($warehouse, 'storage');
        $second = Location::query()->where('full_code', 'MEL-A-01-02')->sole();

        // Putting one unit away puts the pallet away: both units and the pallet take the location, one ledger line each; a second tick is a no-op.
        app(PutawayService::class)->putaway($ub, $storage);
        $this->assertSame([$storage->id, $storage->id, $storage->id, true, true, true], [$ua->fresh()->location_id, $ub->fresh()->location_id, $pallet->fresh()->location_id, $ua->fresh()->putaway_completed, $ub->fresh()->putaway_completed, $pallet->fresh()->putaway_completed]);
        $ledgerPutaway = fn () => StockLedgerEntry::query()->where('movement_type', 'putaway')->whereIn('stock_unit_id', [$ua->id, $ub->id])->count();
        $this->assertSame(2, $ledgerPutaway());
        app(PutawayService::class)->putaway($ua, $storage);
        $this->assertSame(2, $ledgerPutaway(), 'a pallet-mate already put away by the same tick writes nothing');

        // Moving one unit moves the pallet.
        app(MoveService::class)->move($ua->fresh(), $second);
        $this->assertSame([$second->id, $second->id, $second->id], [$ua->fresh()->location_id, $ub->fresh()->location_id, $pallet->fresh()->location_id]);
        $this->assertSame(2, StockLedgerEntry::query()->where('movement_type', 'transfer')->whereIn('stock_unit_id', [$ua->id, $ub->id])->count());

        // Snapshot + weekly storage: the mixed pallet is ONE pallet — one storage line and one CHEP rental line, keyed on its first unit; no carton line.
        app(SnapshotService::class)->take(Carbon::parse('2026-09-08'));
        $this->assertSame([2, 2], [StockSnapshot::query()->where('pallet_id', $pallet->id)->count(), StockSnapshot::query()->whereIn('stock_unit_id', [$ua->id, $ub->id])->count()]);
        $summary = app(SnapshotService::class)->summary(Carbon::parse('2026-09-08'))->firstWhere('client_id', $client->id);
        $this->assertSame([1, 0, 16], [$summary['pallets'], $summary['carton_units'], $summary['cartons']]);
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-08'));
        $code = fn (string $code) => Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->where('source_type', 'snapshot');
        $this->assertSame(1, $code('WH-STORAGE-PLT-WK')->count(), 'one pallet storage line for the mixed pallet');
        $this->assertSame($ua->id, (int) $code('WH-STORAGE-PLT-WK')->value('source_id'), 'keyed on the pallet\'s first unit');
        $this->assertSame(1, $code('WH-PALLET-RENT-POOL-WK')->count(), 'one CHEP rental line');
        $this->assertSame(0, $code('WH-STORAGE-CTN-WK')->count() + $code('WH-STORAGE-CBM-WK')->count(), 'the cartons on the pallet are not billed as loose cartons');
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-08'));
        $this->assertSame(1, $code('WH-STORAGE-PLT-WK')->count(), 're-run adds nothing');

        // The pallet is empty once its last carton is gone, and in use again when stock reappears.
        app(StockLedger::class)->record($ua->fresh(), 'adjust', -10, ['source_type' => 'test', 'source_id' => null]);
        $pallet->refreshStatus();
        $this->assertSame('in_use', $pallet->fresh()->status, 'the other line still has cartons');
        app(StockLedger::class)->record($ub->fresh(), 'adjust', -6, ['source_type' => 'test', 'source_id' => null]);
        $pallet->refreshStatus();
        $this->assertSame('empty', $pallet->fresh()->status);
    }
}
