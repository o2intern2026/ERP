<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\StorageBillingService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\Location;
use App\Modules\Warehouse\Models\Pallet;
use App\Modules\Warehouse\Models\StockLedgerEntry;
use App\Modules\Warehouse\Models\StockSnapshot;
use App\Modules\Warehouse\Models\StockTransfer;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\SnapshotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CHANGE_REQUESTS #167 跨仓调拨 + 计费仓库: a transfer moves pallets / units MEL → SYD (draft → dispatched: transit, not allocatable →
 * received: the destination dock, then the ordinary putaway); who pays is the planner's choice — internal transfers bill nothing and keep the
 * billing warehouse, client-requested ones raise the transfer charges and switch storage to the destination's rates.
 */
class StockTransferTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** A mixed pallet (line A 10 ctn + line B 6 ctn) and a loose carton unit C (4 ctn), all put away in MEL. @return array{Pallet, StockUnit, StockUnit, StockUnit} */
    private function stockInMel(Client $client, Warehouse $mel): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $mel->id, 'inbound_type' => 'loose_truck']);
        [$a, $b, $c] = array_values(app(AsnService::class)->addLines($asn, [
            ['description' => 'A', 'consignment_mark' => 'TRA', 'expected_cartons' => 10], ['description' => 'B', 'consignment_mark' => 'TRB', 'expected_cartons' => 6], ['description' => 'C', 'consignment_mark' => 'TRC', 'expected_cartons' => 4],
        ]));
        $rcv = $this->location($mel, 'receiving');
        [$ua] = app(ReceivingService::class)->receiveLine($a, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1200, 'weight_kg' => 300, 'pallet_source' => 'client_own']]], $rcv);
        $pallet = $ua->fresh()->pallet;
        [$ub] = app(ReceivingService::class)->receiveLine($b, ['received_cartons' => 6, 'units' => [['unit_type' => 'carton', 'carton_qty' => 6, 'pallet_no' => $pallet->pallet_no]]], $rcv);
        [$uc] = app(ReceivingService::class)->receiveLine($c, ['received_cartons' => 4, 'units' => [['unit_type' => 'carton', 'carton_qty' => 4]]], $rcv);
        app(PutawayService::class)->putaway($ua->fresh(), $this->location($mel, 'storage'));
        app(PutawayService::class)->putaway($uc->fresh(), $this->location($mel, 'storage'));

        return [$pallet->fresh(), $ua->fresh(), $ub->fresh(), $uc->fresh()];
    }

    public function test_an_internal_transfer_moves_the_pallet_and_the_loose_unit_through_transit_to_the_destination_dock_bills_nothing_and_keeps_the_billing_warehouse(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client();
        $mel = $this->warehouse();
        $syd = $this->warehouse('SYD');
        [$pallet, $ua, $ub, $uc] = $this->stockInMel($client, $mel);
        $this->assertSame([$mel->id, $mel->id], [$ua->billing_warehouse_id, $pallet->billing_warehouse_id], 'receiving books the ASN warehouse as the billing warehouse');

        // Refusals: another client's unit, a reserved unit, an unknown code, the same warehouse twice; nothing is written.
        $other = $this->client(['code' => 'TRF2', 'name' => 'Transfer Two']);
        [, , , $foreign] = $this->stockInMel($other, $mel);
        $post = fn (array $overrides = []) => $this->post(route('warehouse.transfers.store'), $overrides + ['client_id' => $client->id, 'from_warehouse_id' => $mel->id, 'to_warehouse_id' => $syd->id, 'charge_to' => 'internal', 'codes' => $pallet->pallet_no."\n".$uc->label_code]);
        $post(['codes' => $foreign->label_code])->assertSessionHasErrors('codes');
        $uc->update(['qty_reserved' => 1]);
        $post()->assertSessionHasErrors('codes');
        $uc->update(['qty_reserved' => 0]);
        $post(['codes' => 'NOPE-1'])->assertSessionHasErrors('codes');
        $post(['to_warehouse_id' => $mel->id])->assertSessionHasErrors('to_warehouse_id');
        $this->assertSame(0, StockTransfer::query()->count());

        // Create: the pallet code brings both lines, the loose unit by its label; one Job; the page lists the lines and the dispatch form.
        $post()->assertSessionHasNoErrors()->assertRedirect();
        $transfer = StockTransfer::query()->sole();
        $this->assertSame(['draft', 'internal', $ua->job_id, 3, 20], [$transfer->status, $transfer->charge_to, $transfer->job_id, $transfer->lines()->count(), (int) $transfer->lines()->sum('qty')]);
        $this->assertMatchesRegularExpression('/^TRF-\d{8}-\d{4}$/', $transfer->transfer_no);
        $post()->assertSessionHasErrors('codes'); // already on an open transfer
        $page = $this->get(route('warehouse.transfers.show', $transfer))->assertOk()->assertSee($ua->label_code)->assertSee($ub->label_code)->assertSee($uc->label_code)->assertSee('id="transfer-dispatch"', false);
        $this->assertDoesNotMatchRegularExpression('/warehouse\.transfers\./', $page->getContent());
        $this->get(route('warehouse.transfers.index'))->assertOk()->assertSee($transfer->transfer_no)->assertSee(__('warehouse.nav_transfers'));

        // Dispatch: every unit into MEL's transit area, not allocatable, the pallet too; one ledger line each; the event carries the counts.
        $this->post(route('warehouse.transfers.dispatch', $transfer), ['vehicle' => 'XYZ-123', 'driver_name' => 'Lee'])->assertSessionHasNoErrors();
        $transit = Location::query()->where('warehouse_id', $mel->id)->where('type', 'transit')->sole();
        $this->assertSame('MEL-TRN-01-01', $transit->full_code);
        $this->assertSame(['dispatched', 'XYZ-123'], [$transfer->fresh()->status, $transfer->fresh()->vehicle]);
        foreach ([$ua, $ub, $uc] as $u) {
            $this->assertSame([$transit->id, false, false], [$u->fresh()->location_id, $u->fresh()->putaway_completed, $u->fresh()->isAllocatable()]);
        }
        $this->assertSame([$transit->id, false], [$pallet->fresh()->location_id, $pallet->fresh()->putaway_completed]);
        $this->assertSame(3, StockLedgerEntry::query()->where('source_type', 'transfer')->where('source_id', $transfer->id)->count());
        $dispatched = OutboxEvent::query()->where('event_name', 'stock.transfer.dispatched')->sole();
        $this->assertSame([$transfer->id, 'internal', 1, 3, 20], [$dispatched->payload['transfer_id'], $dispatched->payload['charge_to'], $dispatched->payload['pallet_count'], $dispatched->payload['unit_count'], $dispatched->payload['carton_count']]);
        $this->post(route('warehouse.transfers.cancel', $transfer))->assertSessionHasErrors('dispatch'); // not a draft any more

        // Receive at SYD (an operator): units and pallet in SYD's receiving area, not yet put away; billing warehouse still MEL (internal).
        $operator = $this->staff('warehouse_operator');
        $sydDock = $this->location($syd, 'receiving');
        $this->actingAs($operator)->post(route('warehouse.transfers.receive', $transfer), ['receiving_location_id' => $this->location($mel, 'receiving')->id])->assertSessionHasErrors('receiving_location_id');
        $this->actingAs($operator)->post(route('warehouse.transfers.receive', $transfer), ['receiving_location_id' => $sydDock->id])->assertSessionHasNoErrors();
        $this->assertSame('received', $transfer->fresh()->status);
        foreach ([$ua, $ub, $uc] as $u) {
            $this->assertSame([$syd->id, $sydDock->id, false, $mel->id], [$u->fresh()->warehouse_id, $u->fresh()->location_id, $u->fresh()->putaway_completed, $u->fresh()->billing_warehouse_id]);
        }
        $this->assertSame([$syd->id, $sydDock->id, $mel->id], [$pallet->fresh()->warehouse_id, $pallet->fresh()->location_id, $pallet->fresh()->billing_warehouse_id]);
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'stock.transfer.received')->count());
        // The ordinary SYD putaway takes over: the pallet as a whole, the loose unit on its own; then the stock is allocatable again.
        $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk()->assertSee($ua->label_code)->assertSee($uc->label_code);
        app(PutawayService::class)->putaway($ub->fresh(), $this->location($syd, 'storage'));
        app(PutawayService::class)->putaway($uc->fresh(), $this->location($syd, 'storage'));
        $this->assertSame([true, true, true, true], [$ua->fresh()->isAllocatable(), $ub->fresh()->isAllocatable(), $uc->fresh()->isAllocatable(), $pallet->fresh()->putaway_completed]);

        // Billing: the internal transfer raises no charge at all; weekly storage still prices MEL's row (450) although the goods sit in SYD.
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(0, Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->whereIn('code', ['WH-TRANSFER-OUT-PLT', 'WH-TRANSFER-IN-PLT', 'TR-TRANSFER-PLT']))->count());
        $plt = RateItem::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->whereNull('warehouse_id')->sole();
        $plt->replicate()->fill(['warehouse_id' => $syd->id, 'rate_cents' => 600])->save();
        app(SnapshotService::class)->take(Carbon::parse('2026-09-08'));
        $this->assertSame([$syd->id, $mel->id], [StockSnapshot::query()->where('stock_unit_id', $ua->id)->value('warehouse_id'), StockSnapshot::query()->where('stock_unit_id', $ua->id)->value('billing_warehouse_id')]);
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-08'));
        $storage = Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->where('source_id', $ua->id)->sole();
        $this->assertSame(450, (int) $storage->amount_cents, 'internal move: the client keeps paying the MEL rate');
    }

    public function test_a_client_requested_transfer_raises_the_transfer_charges_and_moves_storage_to_the_destination_rates(): void
    {
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $client = $this->client();
        $mel = $this->warehouse();
        $syd = $this->warehouse('SYD');
        [$pallet, $ua, $ub, $uc] = $this->stockInMel($client, $mel);
        $plt = RateItem::query()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->whereNull('warehouse_id')->sole();
        $plt->replicate()->fill(['warehouse_id' => $syd->id, 'rate_cents' => 600])->save();

        $this->post(route('warehouse.transfers.store'), ['client_id' => $client->id, 'from_warehouse_id' => $mel->id, 'to_warehouse_id' => $syd->id, 'charge_to' => 'client', 'codes' => $pallet->pallet_no."\n".$uc->label_code])->assertSessionHasNoErrors();
        $transfer = StockTransfer::query()->sole();
        $this->post(route('warehouse.transfers.dispatch', $transfer), [])->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        $charge = fn (string $code) => Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', $code))->where('source_type', 'transfer')->where('source_id', $transfer->id);
        $this->assertSame([1, 1, 0], [$charge('WH-TRANSFER-OUT-PLT')->count(), $charge('TR-TRANSFER-PLT')->count(), $charge('WH-TRANSFER-IN-PLT')->count()], 'dispatch: pick + load and transport, per pallet');
        $this->assertSame([1.0, 'needs_review', $transfer->job_id], [(float) $charge('WH-TRANSFER-OUT-PLT')->value('qty'), $charge('WH-TRANSFER-OUT-PLT')->value('status'), (int) $charge('WH-TRANSFER-OUT-PLT')->value('job_id')], 'one pallet; no Edward rate yet → Missing Rate for finance, never $0 billed');

        $this->post(route('warehouse.transfers.receive', $transfer), ['receiving_location_id' => $this->location($syd, 'receiving')->id])->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(1, $charge('WH-TRANSFER-IN-PLT')->count(), 'receipt: unload + putaway, per pallet');
        $this->assertSame([$syd->id, $syd->id, $syd->id], [$ua->fresh()->billing_warehouse_id, $uc->fresh()->billing_warehouse_id, $pallet->fresh()->billing_warehouse_id], 'the client asked for SYD: storage is SYD\'s from now on');
        app(PutawayService::class)->putaway($ua->fresh(), $this->location($syd, 'storage'));
        app(PutawayService::class)->putaway($uc->fresh(), $this->location($syd, 'storage'));
        app(SnapshotService::class)->take(Carbon::parse('2026-09-08'));
        app(StorageBillingService::class)->billWeek(Carbon::parse('2026-09-08'));
        $storage = Charge::query()->withoutGlobalScopes()->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-STORAGE-PLT-WK'))->where('source_id', $ua->id)->sole();
        $this->assertSame(600, (int) $storage->amount_cents, 'client-requested move: the SYD rate applies');

        // A dispatcher may create and dispatch but not receive; a client user sees nothing.
        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher)->get(route('warehouse.transfers.index'))->assertOk();
        $this->actingAs($dispatcher)->post(route('warehouse.transfers.receive', $transfer), ['receiving_location_id' => $this->location($syd, 'receiving')->id])->assertForbidden();
        $this->actingAs($this->clientUser($client))->get(route('warehouse.transfers.index'))->assertForbidden();
    }
}
