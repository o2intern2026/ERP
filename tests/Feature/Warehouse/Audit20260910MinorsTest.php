<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\Package;
use App\Modules\Warehouse\Models\ReturnReceipt;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Models\WarehouseTask;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\OutboundService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\StockService;
use App\Modules\Warehouse\Services\StocktakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Blocking-bug audit 2026-09-10, Warehouse minors: buttons only where the role's POST is accepted, forms that keep what was typed,
 * completion buttons that wait for their preconditions (in Chinese), receiving without a 收货区, the units rule.
 */
class Audit20260910MinorsTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** @return array{0: Warehouse, 1: list<StockUnit>} */
    private function stock(int $clientId, array $cartons): array
    {
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $clientId, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => array_sum($cartons)]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => array_sum($cartons), 'units' => array_map(fn ($q) => ['unit_type' => 'carton', 'carton_qty' => $q], $cartons)], $this->location($warehouse, 'receiving'));
        foreach ($units as $u) {
            app(PutawayService::class)->putaway($u, $this->location($warehouse, 'storage'));
        }

        return [$warehouse, array_map(fn ($u) => $u->fresh(), $units)];
    }

    public function test_stocktake_count_form_only_for_warehouse_roles_and_close_waits_for_every_line(): void
    {
        $client = $this->client();
        [$warehouse, $units] = $this->stock($client->id, [10, 8]);
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $stocktake = app(StocktakeService::class)->open(['warehouse_id' => $warehouse->id, 'client_id' => $client->id]);
        [$l1, $l2] = $stocktake->lines()->orderBy('id')->get();

        // finance may read the page (routes.php) but not count: no 记录 form, read-only cells with an explicit 未计 marker.
        $this->actingAs($this->staff('finance'))->get(route('warehouse.stocktakes.show', $stocktake))->assertOk()
            ->assertDontSee('action="'.route('warehouse.stocktakes.count', [$stocktake, $l1]).'"', false)
            ->assertDontSee('action="'.route('warehouse.stocktakes.scan', $stocktake).'"', false)
            ->assertSee(__('warehouse.stocktakes.not_counted'));

        // The supervisor gets the forms, but 关闭盘点 is disabled with the reason until every line is counted.
        $this->actingAs($supervisor)->get(route('warehouse.stocktakes.show', $stocktake))->assertOk()
            ->assertSee('action="'.route('warehouse.stocktakes.count', [$stocktake, $l1]).'"', false)
            ->assertSee(__('warehouse.stocktakes.close_blocked', ['count' => 2]))
            ->assertSee('<button type="submit" disabled>'.__('warehouse.stocktakes.close'), false);
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.close', $stocktake))->assertRedirect()
            ->assertSessionHasErrors(['close' => __('warehouse.stocktakes.errors.uncounted', ['count' => 2, 'labels' => $units[0]->label_code.', '.$units[1]->label_code])]);

        // A scan that misses keeps the typed code so one character can be fixed.
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.scan', $stocktake), ['code' => 'NOPE-1', 'counted_qty' => 3])->assertSessionHasErrors('code')->assertSessionHasInput('code', 'NOPE-1');
        $this->actingAs($supervisor)->get(route('warehouse.stocktakes.show', $stocktake))->assertOk()->assertSee('value="NOPE-1"', false);

        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.count', [$stocktake, $l1]), ['counted_qty' => 10])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.count', [$stocktake, $l2]), ['counted_qty' => 8])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->get(route('warehouse.stocktakes.show', $stocktake))->assertOk()->assertDontSee(__('warehouse.stocktakes.close_blocked', ['count' => 2]))->assertDontSee('<button type="submit" disabled>', false);
        $this->actingAs($supervisor)->post(route('warehouse.stocktakes.close', $stocktake))->assertSessionHasNoErrors();
        $this->assertSame('closed', $stocktake->fresh()->status);
    }

    public function test_dispatcher_sees_receipt_numbers_but_no_links_it_cannot_open(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => 4, 'consignment_mark' => 'DSP-1']]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 4, 'units' => [['unit_type' => 'carton', 'carton_qty' => 4]]], $this->location($warehouse, 'receiving'));
        $receipt = GoodsReceipt::query()->firstOrFail();
        $dispatcher = $this->staff('dispatcher');

        // The ASN page is open to the dispatcher (routes.php) but the receipts pages are not: number as text, no 查看 / PDF link.
        $this->actingAs($dispatcher)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee($receipt->receipt_no)
            ->assertDontSee(route('warehouse.receipts.show', $receipt))->assertDontSee(route('warehouse.receipts.pdf', $receipt));
        $this->actingAs($dispatcher)->get(route('warehouse.receipts.show', $receipt))->assertForbidden();
        $this->actingAs($this->staff('finance'))->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(route('warehouse.receipts.show', $receipt));

        // Global search: the 入库单 hit only for roles that can open it; the ASN hit for the whole read group; nothing for transport.
        $this->actingAs($dispatcher)->get('/admin/search?q='.$receipt->receipt_no)->assertOk()->assertDontSee(route('warehouse.receipts.show', $receipt))->assertSee($asn->asn_no);
        $this->actingAs($this->staff('finance'))->get('/admin/search?q='.$receipt->receipt_no)->assertOk()->assertSee(route('warehouse.receipts.show', $receipt));
        $this->actingAs($this->staff('transport_operator'))->get('/admin/search?q='.$asn->asn_no)->assertOk()->assertDontSee(route('warehouse.asns.show', $asn))->assertSee(__('platform.search.empty', ['q' => $asn->asn_no]));
    }

    public function test_receiving_without_a_receiving_location_explains_itself_and_the_nav_reaches_the_locations_page(): void
    {
        $client = $this->client();
        $this->warehouse(); // MEL with locations
        $syd = Warehouse::query()->create(['code' => 'SYD', 'name' => 'Sydney DC', 'active' => true]); // no locations yet — the realistic slip
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $syd->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => 2]]);
        $operator = $this->staff('warehouse_operator');

        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertOk()
            ->assertSee(__('warehouse.receiving.no_receiving_location', ['code' => 'SYD']))
            ->assertSee(route('warehouse.locations.index'))
            ->assertDontSee('name="receiving_location_id"', false);
        $this->actingAs($operator)->get(route('warehouse.receiving.bulk_form', $asn))->assertOk()->assertSee(__('warehouse.receiving.no_receiving_location', ['code' => 'SYD']));
        $this->actingAs($operator)->get(route('warehouse.receiving.unplanned.form'))->assertOk()->assertSee('id="no-location-notice"', false);

        // 库位配置 is now in the warehouse nav for the roles that can create locations, and the page flags the warehouse without a 收货区.
        $this->actingAs($operator)->get(route('warehouse.asns.index'))->assertOk()->assertSee(__('warehouse.nav_locations'));
        $this->actingAs($this->staff('finance'))->get(route('warehouse.asns.index'))->assertOk()->assertDontSee(__('warehouse.nav_locations'));
        $this->actingAs($operator)->get(route('warehouse.locations.index'))->assertOk()->assertSee(__('warehouse.locations.no_receiving', ['code' => 'SYD']))->assertDontSee(__('warehouse.locations.no_receiving', ['code' => 'MEL']));
    }

    public function test_units_are_required_only_when_cartons_were_received(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line, $empty] = app(AsnService::class)->addLines($asn, [['description' => 'Goods', 'expected_cartons' => 10], ['description' => 'Missing', 'expected_cartons' => 3]]);
        $operator = $this->staff('warehouse_operator');
        $location = $this->location($warehouse, 'receiving')->id;

        // 10 cartons booked with no unit rows used to pass validation and book stockless cartons (required_if:received_cartons,>,0 never fired).
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), ['receiving_location_id' => $location, 'received_cartons' => 10])
            ->assertSessionHasErrors(['units' => __('warehouse.receiving.units_required')]);
        $this->assertSame(0, StockUnit::query()->count());
        $this->assertNull($line->fresh()->receiptLine); // nothing booked on the 入库单 either

        // Nothing arrived: no unit row needed (the old rule refused exactly this case).
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $empty]), ['receiving_location_id' => $location, 'received_cartons' => 0, 'variance_reason' => 'not on truck'])
            ->assertSessionDoesntHaveErrors('units')->assertRedirect(route('warehouse.asns.show', $asn));
        $this->assertSame(0, $empty->fresh()->received_cartons);
    }

    public function test_locations_page_and_scan_gun_forms_keep_what_was_typed_after_a_refusal(): void
    {
        $client = $this->client();
        [$warehouse, $units] = $this->stock($client->id, [5]);
        $supervisor = $this->staff('warehouse_supervisor');

        // 新建仓库 with a duplicate code: the error shows and all six fields survive.
        $this->actingAs($supervisor)->post(route('warehouse.warehouses.store'), ['code' => 'MEL', 'name' => 'Melbourne DC 2', 'address' => '1 Test St', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3026'])
            ->assertSessionHasErrors('code')->assertSessionHasInput('name', 'Melbourne DC 2');
        $this->actingAs($supervisor)->get(route('warehouse.locations.index'))->assertOk()->assertSee('value="Melbourne DC 2"', false)->assertSee('value="Laverton"', false)->assertSee('value="3026"', false);

        // 新建库位 with a hyphenated zone: zone / aisle / bin and the chosen type survive.
        $this->actingAs($supervisor)->post(route('warehouse.locations.store'), ['warehouse_id' => $warehouse->id, 'zone' => 'A-1', 'aisle' => '01', 'bin' => '05', 'type' => 'pickface'])->assertSessionHasErrors('zone');
        $this->actingAs($supervisor)->get(route('warehouse.locations.index'))->assertOk()->assertSee('value="A-1"', false)->assertSee('<option value="pickface" selected>', false);

        // Stock move to an unknown code: the code and the reason stay in the 移库 card.
        $this->actingAs($supervisor)->post(route('warehouse.stock.move', $units[0]), ['_form' => 'move', 'location_code' => 'MEL-ZZ-99-99', 'reason' => 'test reason'])->assertSessionHasErrors('location_code');
        $this->actingAs($supervisor)->get(route('warehouse.stock.show', $units[0]))->assertOk()->assertSee('value="MEL-ZZ-99-99"', false)->assertSee('value="test reason"', false);

        // Putaway: the refused row keeps its code, other rows stay blank.
        $pending = app(ReceivingService::class)->receiveLine(app(AsnService::class)->addLines($units[0]->asnLine->asn, [['description' => 'More', 'expected_cartons' => 2]])[0], ['received_cartons' => 2, 'units' => [['unit_type' => 'carton', 'carton_qty' => 1], ['unit_type' => 'carton', 'carton_qty' => 1]]], $this->location($warehouse, 'receiving'));
        $this->actingAs($supervisor)->post(route('warehouse.putaway.store', $pending[0]), ['location_code' => 'MEL-ZZ-99-99'])->assertSessionHasErrors('location_code');
        $page = $this->actingAs($supervisor)->get(route('warehouse.putaway.index'))->assertOk();
        $this->assertSame(1, substr_count($page->getContent(), 'value="MEL-ZZ-99-99"'));
    }

    /** i18n sweep 2026-09-10 (A2): every Warehouse service refusal a person can trigger from a form comes back in Chinese, never the developer message. */
    public function test_warehouse_service_refusals_render_in_chinese_on_the_page(): void
    {
        $client = $this->client();
        [$warehouse, $units] = $this->stock($client->id, [10, 8]);
        $supervisor = $this->staff('warehouse_supervisor');
        $this->actingAs($supervisor);
        $storage = $this->location($warehouse, 'storage');
        $receiving = $this->location($warehouse, 'receiving');

        // Putaway into the receiving area: not a valid target.
        $pending = app(ReceivingService::class)->receiveLine(app(AsnService::class)->addLines($units[0]->asnLine->asn, [['description' => 'More', 'expected_cartons' => 3]])[0], ['received_cartons' => 2, 'damaged_cartons' => 1, 'units' => [['unit_type' => 'carton', 'carton_qty' => 2]]], $receiving);
        $good = collect($pending)->firstWhere('condition', 'good');
        $damaged = collect($pending)->firstWhere('condition', 'damaged');
        $this->from(route('warehouse.putaway.index'))->followingRedirects()->post(route('warehouse.putaway.store', $good), ['location_code' => $receiving->full_code])->assertOk()
            ->assertSee(__('warehouse.putaway.errors.invalid_target', ['code' => $receiving->full_code, 'label' => $good->label_code]))->assertDontSee('not a valid putaway target');
        // Damaged stock put away into storage: quarantine only.
        $this->from(route('warehouse.putaway.index'))->followingRedirects()->post(route('warehouse.putaway.store', $damaged), ['location_code' => $storage->full_code])->assertOk()
            ->assertSee(__('warehouse.putaway.errors.held_needs_quarantine'))->assertDontSee('must be put away into a quarantine location');

        // Generate orders on an ASN that is still booked.
        $booked = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel']);
        $this->from(route('warehouse.asns.show', $booked))->followingRedirects()->post(route('warehouse.asns.generate_orders', $booked))->assertOk()
            ->assertSee(__('warehouse.asns.errors.generate_after_putaway'))->assertDontSee('Orders are generated after putaway');

        // Move: a quarantined unit may only go to a quarantine location; a reserved unit cannot be quarantined.
        app(PutawayService::class)->putaway($damaged, $this->location($warehouse, 'quarantine'));
        $this->from(route('warehouse.stock.show', $damaged))->followingRedirects()->post(route('warehouse.stock.move', $damaged), ['_form' => 'move', 'location_code' => $storage->full_code])->assertOk()
            ->assertSee(__('warehouse.moves.errors.held_needs_quarantine'))->assertDontSee('may only be moved between quarantine locations');
        app(StockService::class)->reserve($client->id, 77, [['order_line_id' => 1, 'asn_line_id' => $units[1]->asn_line_id, 'qty' => 18]]); // both units of the line
        $reserved = $units[1]->fresh()->qty_reserved;
        $this->assertGreaterThan(0, $reserved);
        $this->from(route('warehouse.stock.show', $units[1]))->followingRedirects()->post(route('warehouse.stock.quarantine', $units[1]), ['_form' => 'quarantine', 'condition' => 'damaged', 'reason' => 'forklift'])->assertOk()
            ->assertSee(__('warehouse.moves.errors.reserved', ['label' => $units[1]->label_code, 'qty' => $reserved]))->assertDontSee('release the reservations before quarantining');

        // Stocktake: counting after the close.
        $stocktake = app(StocktakeService::class)->open(['warehouse_id' => $warehouse->id, 'client_id' => $client->id]);
        foreach ($stocktake->lines()->with('stockUnit')->get() as $l) {
            app(StocktakeService::class)->count($l, $l->expected_qty);
        }
        app(StocktakeService::class)->close($stocktake->fresh());
        $line = $stocktake->lines()->firstOrFail();
        $this->from(route('warehouse.stocktakes.show', $stocktake))->followingRedirects()->post(route('warehouse.stocktakes.count', [$stocktake, $line]), ['counted_qty' => 3])->assertOk()
            ->assertSee(__('warehouse.stocktakes.errors.not_counting'))->assertDontSee('no longer counting');
    }

    /** i18n sweep 2026-09-10 (A2): return-receipt sequencing refusals in Chinese. */
    public function test_return_receipt_sequencing_refusals_render_in_chinese(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'RT2', 'cartons' => 5]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $this->actingAs($supervisor)->post(route('warehouse.returns.store'), ['order_no' => $order->order_no, 'warehouse_id' => $warehouse->id])->assertRedirect();
        $receipt = ReturnReceipt::query()->firstOrFail();
        $line = $receipt->lines()->firstOrFail();

        $this->actingAs($supervisor)->from(route('warehouse.returns.show', $receipt))->followingRedirects()->post(route('warehouse.returns.inspect', [$receipt, $line]), ['disposition' => 'available'])->assertOk()
            ->assertSee(__('warehouse.returns.errors.receive_first'))->assertDontSee('Complete receiving before inspecting');

        $this->actingAs($supervisor)->post(route('warehouse.returns.receive', [$receipt, $line]), ['received_qty' => 2, 'condition' => 'good'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_receiving', $receipt))->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->from(route('warehouse.returns.show', $receipt))->followingRedirects()->post(route('warehouse.returns.receive', [$receipt, $line]), ['received_qty' => 1, 'condition' => 'good'])->assertOk()
            ->assertSee(__('warehouse.returns.errors.not_receiving'))->assertDontSee('no longer receiving');

        $this->actingAs($supervisor)->post(route('warehouse.returns.inspect', [$receipt, $line]), ['disposition' => 'available'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->from(route('warehouse.returns.show', $receipt))->followingRedirects()->post(route('warehouse.returns.inspect', [$receipt, $line]), ['disposition' => 'damaged'])->assertOk()
            ->assertSee(__('warehouse.returns.errors.already_inspected'))->assertDontSee('already inspected');
    }

    public function test_return_receipt_completion_buttons_wait_for_their_preconditions_in_chinese(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'RT1', 'cartons' => 5]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $this->actingAs($supervisor)->post(route('warehouse.returns.store'), ['order_no' => $order->order_no, 'warehouse_id' => $warehouse->id])->assertRedirect();
        $receipt = ReturnReceipt::query()->firstOrFail();
        $line = $receipt->lines()->firstOrFail();

        $this->actingAs($supervisor)->get(route('warehouse.returns.show', $receipt))->assertOk()
            ->assertSee('<button type="submit" disabled>'.__('warehouse.returns.complete_receiving'), false)
            ->assertSee(__('warehouse.returns.errors.lines_unreceived', ['count' => 1]));
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_receiving', $receipt))->assertSessionHasErrors(['receipt' => __('warehouse.returns.errors.lines_unreceived', ['count' => 1])]);

        $this->actingAs($supervisor)->post(route('warehouse.returns.receive', [$receipt, $line]), ['received_qty' => 2, 'condition' => 'good'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->get(route('warehouse.returns.show', $receipt))->assertOk()->assertDontSee('<button type="submit" disabled>', false);
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_receiving', $receipt))->assertSessionHasNoErrors();

        $this->actingAs($supervisor)->get(route('warehouse.returns.show', $receipt))->assertOk()
            ->assertSee('<button type="submit" disabled>'.__('warehouse.returns.complete_inspection'), false)
            ->assertSee(__('warehouse.returns.errors.lines_uninspected', ['count' => 1]));
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_inspection', $receipt))->assertSessionHasErrors(['receipt' => __('warehouse.returns.errors.lines_uninspected', ['count' => 1])]);
        $this->actingAs($supervisor)->post(route('warehouse.returns.inspect', [$receipt, $line]), ['disposition' => 'available'])->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('warehouse.returns.complete_inspection', $receipt))->assertSessionHasNoErrors();
        $this->assertSame('inspected', $receipt->fresh()->status);
    }

    public function test_outbound_board_marks_a_held_fulfilment_instead_of_offering_dispatch(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'HLD1', 'cartons' => 6, 'weight_kg' => 30]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $fulfilment = (int) DB::table('fulfilments')->where('order_id', $order->id)->value('id');
        $this->actingAs($this->staff('finance'))->post(route('orders.holds.store', $order), ['hold_type' => 'financial', 'reason' => 'deposit outstanding'])->assertRedirect();

        // Picking and packing are allowed under a financial hold (§3.8 #7) …
        $outbound = app(OutboundService::class);
        $this->actingAs($operator);
        $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id);
        $task = WarehouseTask::query()->where('task_type', 'pick')->firstOrFail();
        $outbound->confirmPick($task->lines()->firstOrFail(), 2, $operator->id);
        $outbound->pack($fulfilment, [['package_type' => 'carton', 'weight_kg' => 12.5]], $operator->id);
        $this->assertSame(1, Package::query()->count());

        // … but the board says the handover is blocked instead of offering a form that can only be refused.
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()
            ->assertSee(__('platform.exceptions.hold_types.financial'))->assertSee(__('warehouse.outbound.errors.financial_hold'))
            ->assertDontSee('action="'.route('warehouse.outbound.dispatch', $fulfilment).'"', false);
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilment), ['pallet_count' => 0, 'handed_to' => 'carrier'])
            ->assertSessionHasErrors(['pallet_count' => __('warehouse.outbound.errors.financial_hold')]);

        // i18n/zh sweep review: the stock unit page names the reservation line and status and the ledger source in Chinese, not "line 3" / "consumed" / "task #1".
        $unit = StockUnit::query()->where('asn_line_id', $asnLines[0]->id)->firstOrFail();
        $reservation = DB::table('stock_reservations')->where('stock_unit_id', $unit->id)->orderByDesc('id')->first(['order_line_id', 'status']);
        $this->actingAs($operator)->get(route('warehouse.stock.show', $unit))->assertOk()
            ->assertSee(__('warehouse.stock.reservation_ref', ['order' => $order->id, 'line' => $reservation->order_line_id]))
            ->assertSee(__('warehouse.reservation_statuses.'.$reservation->status))
            ->assertSee(__('platform.source_types.task').' #')
            ->assertDontSee('/ line ')
            ->assertDontSee('<td>'.$reservation->status.'</td>', false);
    }

    /**
     * i18n/zh sweep review: a second 'errors' key later in the same lang array silently replaced the first one, so the eight
     * outbound refusals rendered as raw keys ("warehouse.outbound.errors.no_candidates") while every test compared key to key.
     * Guard: the keys resolve, and what the operator sees after a refused release is Chinese, not a key.
     */
    public function test_outbound_refusal_strings_resolve_from_lang_and_reach_the_board_in_chinese(): void
    {
        foreach (['no_candidates', 'picked_range', 'pick_confirmed', 'pack_after_pick', 'already_packed', 'need_package', 'dispatch_after_pack', 'already_dispatched', 'financial_hold'] as $key) {
            $this->assertTrue(Lang::has('warehouse.outbound.errors.'.$key), "missing lang key warehouse.outbound.errors.$key");
            $this->assertMatchesRegularExpression('/\p{Han}/u', __('warehouse.outbound.errors.'.$key), "warehouse.outbound.errors.$key is not Chinese");
        }

        $operator = $this->staff('warehouse_operator');
        $warehouse = $this->warehouse();
        $this->actingAs($operator)->from(route('warehouse.outbound.index'))
            ->post(route('warehouse.outbound.waves.release'), ['warehouse_id' => $warehouse->id, 'client_id' => 999999])
            ->assertRedirect(route('warehouse.outbound.index'))
            ->assertSessionHasErrors(['warehouse_id' => __('warehouse.outbound.errors.no_candidates')]);
        $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk()
            ->assertSee(__('warehouse.outbound.errors.no_candidates'))
            ->assertDontSee('warehouse.outbound.errors');
    }
}
