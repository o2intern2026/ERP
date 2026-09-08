<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\Document;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\WarehouseContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 入库单 (goods receipt) — tester feedback round 3 item 2 (CHANGE_REQUESTS #90–#92): every receiving batch of an ASN (预报单)
 * is its own numbered 入库单 ({asn_no}-R{n}) that rolls up to the ASN; 入库完成 snapshots totals and files the PDF;
 * 无预报收货 receives walk-in goods on one screen; the UI says 预报单 (ASN) and reserves 入库单 for the new document.
 */
class GoodsReceiptTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_batches_are_numbered_per_asn_roll_up_to_it_and_completion_files_the_pdf(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$l1, $l2, $l3]] = $this->asnWithLines($client->id, $warehouse->id, [10, 8, 5]);
        $receiving = app(ReceivingService::class);
        $service = app(GoodsReceiptService::class);
        $rcv = $this->location($warehouse, 'receiving');

        $units1 = $receiving->receiveLine($l1, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10, 'pallet_source' => 'chep']]], $rcv, $operator->id);
        $units2 = $receiving->receiveLine($l2, ['received_cartons' => 7, 'damaged_cartons' => 1, 'variance_reason' => 'crushed', 'units' => [['unit_type' => 'carton', 'carton_qty' => 7]]], $rcv, $operator->id);

        $r1 = GoodsReceipt::query()->firstOrFail();
        $this->assertSame($asn->asn_no.'-R1', $r1->receipt_no);
        $this->assertSame([1, 'open', $client->id, $asn->job_id, $operator->id], [$r1->batch_no, $r1->status, $r1->client_id, $r1->job_id, $r1->opened_by]);
        $this->assertSame(2, $r1->lines()->count());
        $this->assertSame(1, GoodsReceipt::query()->count(), 'the second line joins the open batch');
        foreach (array_merge($units1, $units2) as $unit) {
            $this->assertSame($r1->id, $unit->fresh()->goods_receipt_id);
        }
        $this->assertSame($r1->id, StockUnit::query()->where('label_code', 'like', '%-DMG')->firstOrFail()->goods_receipt_id, 'the -DMG quarantine unit belongs to the batch too');
        $this->assertSame(['no' => $r1->receipt_no, 'open' => true], $service->nextReceiptNo($asn->fresh()));

        $service->complete($r1, $operator->id, 'first truck');
        $r1->refresh();
        $this->assertSame('completed', $r1->status);
        $this->assertSame([18, 17, 1, 0, 2, 3, 1], [$r1->expected_cartons, $r1->received_cartons, $r1->damaged_cartons, $r1->variance_cartons, $r1->line_count, $r1->unit_count, $r1->pallet_count]);
        $this->assertSame([$operator->id, 'first truck'], [$r1->completed_by, $r1->notes]);
        $this->assertNotNull($r1->completed_at);
        $document = Document::query()->findOrFail($r1->pdf_document_id);
        $this->assertSame(['goods_receipt', 'asn', $asn->id, $client->id, true, $r1->receipt_no.'.pdf'], [$document->type, $document->related_type, $document->related_id, $document->client_id, $document->client_visible, $document->original_name]);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($document->storage_path));
        $this->assertNull($asn->fresh()->receiving_completed_at, 'one line is still unreceived');
        $this->assertSame(['no' => $asn->asn_no.'-R2', 'open' => false], $service->nextReceiptNo($asn->fresh()));

        // The third line arrives on a later truck → batch 2, its own number, same ASN.
        [$unit3] = $receiving->receiveLine($l3, ['received_cartons' => 5, 'units' => [['unit_type' => 'carton', 'carton_qty' => 5]]], $rcv, $operator->id);
        $r2 = GoodsReceipt::query()->where('batch_no', 2)->firstOrFail();
        $this->assertSame($asn->asn_no.'-R2', $r2->receipt_no);
        $this->assertSame($r2->id, $unit3->fresh()->goods_receipt_id);
        $this->assertSame(1, $r2->lines()->count());

        $service->complete($r2, $operator->id);
        $this->assertNotNull($asn->fresh()->receiving_completed_at, 'every line received → the ASN is fully received');
        $rollup = $service->rollup($asn->fresh());
        $this->assertSame(['expected' => 23, 'received' => 22, 'damaged' => 1, 'variance' => 0, 'received_lines' => 3, 'total_lines' => 3], $rollup);

        try {
            $service->complete($r2->fresh(), $operator->id);
            $this->fail('a completed receipt cannot be completed again');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('warehouse.receipts.errors.not_open', ['no' => $r2->receipt_no]), $e->getMessage());
        }
        $r3 = $service->openFor($asn->fresh(), $operator->id);
        $this->assertSame($asn->asn_no.'-R3', $r3->receipt_no);
        try {
            $service->complete($r3, $operator->id);
            $this->fail('an empty receipt cannot be completed');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('warehouse.receipts.errors.no_lines', ['no' => $r3->receipt_no]), $e->getMessage());
        }
    }

    public function test_pdf_route_renders_open_receipts_as_draft_and_completed_ones_live(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$line]] = $this->asnWithLines($this->client()->id, $warehouse->id, [4]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 4, 'units' => [['unit_type' => 'carton', 'carton_qty' => 4]]], $this->location($warehouse, 'receiving'), $operator->id);
        $receipt = GoodsReceipt::query()->firstOrFail();

        $draft = $this->actingAs($operator)->get(route('warehouse.receipts.pdf', $receipt));
        $draft->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($receipt->receipt_no.'.pdf', $draft->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $draft->getContent());
        // The stylesheet must have been understood: dompdf falls back to Times when font-family is garbled (Blade escaping), and the CJK
        // font must actually be embedded (subset) so the Chinese labels print instead of blanks. Deliberately unconditional: a checkout
        // without storage/fonts/cjk.ttf must fail here instead of staying green (the font is git-ignored — see HANDOFF).
        $this->assertStringNotContainsString('/Times-Roman', $draft->getContent());
        $this->assertNotNull(app(GoodsReceiptService::class)->cjkFont(), 'storage/fonts/cjk.ttf (or PDF_CJK_FONT) is missing in this checkout — copy a CJK TrueType font there');
        $this->assertMatchesRegularExpression('/BaseFont \/[A-Z]{6}\+(?!DejaVu)/', $draft->getContent(), 'the CJK font should be embedded as a subset');

        app(GoodsReceiptService::class)->complete($receipt, $operator->id);
        $this->actingAs($this->staff('finance'))->get(route('warehouse.receipts.pdf', $receipt))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->staff('customer_service'))->get(route('warehouse.receipts.show', $receipt))->assertOk()->assertSee($receipt->receipt_no)->assertSee(__('warehouse.receipt_statuses.completed'));
    }

    public function test_walk_in_goods_are_received_on_one_screen_into_an_unplanned_asn_and_a_completed_receipt(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $rcv = $this->location($warehouse, 'receiving');

        $this->actingAs($operator)->get(route('warehouse.receiving.unplanned.form'))->assertOk()->assertSee(__('warehouse.receiving.unplanned.title'));

        $response = $this->actingAs($operator)->post(route('warehouse.receiving.unplanned.store'), [
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'delivery_reference' => 'ABC-123 / driver Li', 'receiving_location_id' => $rcv->id, 'notes' => 'arrived without notice',
            'rows' => [
                ['consignment_mark' => 'WALK-1', 'description' => 'Boxes', 'received_cartons' => 6, 'damaged_cartons' => 1, 'unit_type' => 'pallet', 'unit_count' => 2, 'weight_kg' => '120', 'variance_reason' => 'no pre-advice'],
                ['consignment_mark' => '', 'description' => 'Loose cartons', 'received_cartons' => 3, 'damaged_cartons' => 0, 'unit_type' => 'carton', 'unit_count' => 1],
                ['consignment_mark' => '', 'description' => '', 'received_cartons' => '', 'damaged_cartons' => 0, 'unit_type' => 'pallet', 'unit_count' => 1], // spare form row, ignored
            ],
        ]);

        $receipt = GoodsReceipt::query()->firstOrFail();
        $response->assertRedirect(route('warehouse.receipts.show', $receipt))->assertSessionHas('status', __('warehouse.receiving.unplanned.done', ['no' => $receipt->receipt_no]));
        $asn = Asn::query()->firstOrFail();
        $this->assertSame([true, false, 'receiving', 'loose_truck', $client->id, 'ABC-123 / driver Li'], [$asn->unplanned, $asn->unplanned_confirmed, $asn->status, $asn->inbound_type, $asn->client_id, $asn->job->reference]);
        $this->assertSame([7, 3], $asn->lines()->orderBy('id')->pluck('expected_cartons')->all(), 'expected = received + damaged, nothing was pre-advised');
        $this->assertSame($asn->asn_no.'-R1', $receipt->receipt_no);
        $this->assertSame(['completed', true, 'ABC-123 / driver Li', 10, 9, 1, 0, 2, 4, 2, 'arrived without notice'], [$receipt->status, $receipt->unplanned, $receipt->delivery_reference, $receipt->expected_cartons, $receipt->received_cartons, $receipt->damaged_cartons, $receipt->variance_cartons, $receipt->line_count, $receipt->unit_count, $receipt->pallet_count, $receipt->notes]);
        $this->assertDatabaseHas('documents', ['id' => $receipt->pdf_document_id, 'type' => 'goods_receipt', 'client_id' => $client->id, 'client_visible' => true]);

        $units = StockUnit::query()->orderBy('id')->get();
        $this->assertCount(4, $units); // 2 pallets + the -DMG unit for line 1, 1 carton unit for line 2
        $this->assertSame([3, 3], $units->where('unit_type', 'pallet')->pluck('qty_on_hand')->all(), 'cartons split evenly across the pallets');
        $this->assertSame(['60.000', '60.000'], $units->where('unit_type', 'pallet')->pluck('weight_kg')->all(), 'total weight split evenly');
        $this->assertTrue($units->every(fn (StockUnit $u) => $u->location_id === $rcv->id && ! $u->putaway_completed && $u->goods_receipt_id === $receipt->id));
        $this->assertSame(1, $units->where('condition', 'damaged')->count());
        $this->actingAs($operator)->get(route('warehouse.receipts.show', $receipt))->assertOk()->assertSee($receipt->receipt_no)->assertSee('WALK-1');

        // The Coordinator rule is untouched: putaway refuses until the unplanned arrival is confirmed.
        $good = $units->firstWhere('condition', 'good');
        try {
            app(PutawayService::class)->putaway($good, $this->location($warehouse, 'storage'));
            $this->fail('putaway should be refused before confirmUnplanned');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Unplanned', $e->getMessage());
        }
        app(AsnService::class)->confirmUnplanned($asn);
        app(PutawayService::class)->putaway($good->fresh(), $this->location($warehouse, 'storage'));
        $this->assertTrue($good->fresh()->putaway_completed);

        // Validation: no rows / rows without a single carton are refused and nothing is created.
        $this->actingAs($operator)->post(route('warehouse.receiving.unplanned.store'), ['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'receiving_location_id' => $rcv->id, 'rows' => []])->assertSessionHasErrors('rows');
        $this->actingAs($operator)->post(route('warehouse.receiving.unplanned.store'), ['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'receiving_location_id' => $rcv->id, 'rows' => [['description' => 'Nothing', 'received_cartons' => 0, 'damaged_cartons' => 0, 'unit_type' => 'carton']]])->assertSessionHasErrors('rows.0.received_cartons');
        $this->assertSame(1, Asn::query()->count());
        $this->actingAs($this->staff('customer_service'))->get(route('warehouse.receiving.unplanned.form'))->assertForbidden();
    }

    public function test_pages_show_the_receipt_section_the_renamed_labels_the_worklist_and_the_nav_entries(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$l1, $l2]] = $this->asnWithLines($this->client()->id, $warehouse->id, [10, 6]);
        app(ReceivingService::class)->receiveLine($l1, ['received_cartons' => 10, 'units' => [['unit_type' => 'pallet', 'carton_qty' => 10]]], $this->location($warehouse, 'receiving'), $operator->id);
        $receipt = GoodsReceipt::query()->firstOrFail();

        // ASN page: 入库单 section + roll-up + renamed labels; the 收货 button stays for the unreceived line only.
        $page = $this->actingAs($operator)->get(route('warehouse.asns.show', $asn));
        $page->assertOk()->assertSee('预报单 (ASN)')->assertDontSee('入库 ASN')->assertDontSee('临时收货单')
            ->assertSee(__('warehouse.asns.receipts'))->assertSee($receipt->receipt_no)->assertSee(__('warehouse.receipt_statuses.open'))
            ->assertSee(__('warehouse.asns.rollup', ['expected' => 16, 'received' => 10, 'damaged' => 0, 'variance' => -6, 'done' => 1, 'total' => 2]))
            ->assertSee(route('warehouse.receiving.form', [$asn, $l2]))->assertDontSee(route('warehouse.receiving.form', [$asn, $l1]))
            ->assertSee(route('warehouse.receipts.complete', $receipt));
        $this->actingAs($operator)->get('/warehouse/asns')->assertOk()->assertSee(__('warehouse.asns.create'))->assertSee(route('warehouse.receipts.index'))->assertSee(route('warehouse.receiving.index'))->assertSee(route('warehouse.receiving.unplanned.form'));

        // The receiving form says which 入库单 this receipt joins; the flash names it.
        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $l2]))->assertOk()->assertSee(__('warehouse.receiving.joins_receipt', ['no' => $receipt->receipt_no]));
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $l2]), ['receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'received_cartons' => 6, 'damaged_cartons' => 0, 'units' => [['unit_type' => 'carton', 'carton_qty' => 6]]])
            ->assertRedirect(route('warehouse.asns.show', $asn))->assertSessionHas('status', __('warehouse.receiving.received', ['line' => $l2->id, 'receipt' => $receipt->receipt_no]));
        $this->assertSame(2, $receipt->lines()->count());

        // 入库完成 from the page → receipts index lists it; the ASN page shows the badge.
        $this->actingAs($operator)->post(route('warehouse.receipts.complete', $receipt), ['notes' => 'all in'])->assertRedirect(route('warehouse.receipts.show', $receipt))->assertSessionHasNoErrors();
        $this->assertSame('completed', $receipt->fresh()->status);
        $this->assertNotNull($asn->fresh()->receiving_completed_at);
        $this->actingAs($this->staff('finance'))->get(route('warehouse.receipts.index'))->assertOk()->assertSee($receipt->receipt_no)->assertSee(__('warehouse.receipt_statuses.completed'));
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.receiving_completed_badge'));
        $this->actingAs($operator)->post(route('warehouse.receipts.complete', $receipt))->assertSessionHasErrors('complete');

        // 待收货 worklist: the unreceived line of another ASN, with the 收货 button for warehouse roles only.
        ['asn' => $other, 'lines' => [$pending]] = $this->asnWithLines($this->client(['name' => 'Second Client'])->id, $warehouse->id, [3], 'loose_truck');
        $this->actingAs($operator)->get(route('warehouse.receiving.index'))->assertOk()->assertSee('Second Client')->assertSee($other->asn_no)->assertSee(route('warehouse.receiving.form', [$other, $pending]))->assertDontSee($l1->description);
        $this->actingAs($cs)->get(route('warehouse.receiving.index'))->assertOk()->assertSee($other->asn_no)->assertDontSee(route('warehouse.receiving.form', [$other, $pending]))->assertDontSee(route('warehouse.receiving.unplanned.form'));
        $this->actingAs($this->staff('dispatcher'))->get(route('warehouse.receiving.index'))->assertForbidden();
    }

    public function test_client_users_cannot_open_the_warehouse_pages_but_can_download_their_receipt_document(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$line]] = $this->asnWithLines($client->id, $warehouse->id, [2]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 2, 'units' => [['unit_type' => 'carton', 'carton_qty' => 2]]], $this->location($warehouse, 'receiving'), $operator->id);
        $receipt = app(GoodsReceiptService::class)->complete(GoodsReceipt::query()->firstOrFail(), $operator->id);

        $clientUser = $this->clientUser($client);
        $this->actingAs($clientUser)->get('/warehouse/receipts')->assertForbidden();
        $this->actingAs($clientUser)->get(route('portal.documents.download', $receipt->pdf_document_id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->clientUser())->get(route('portal.documents.download', $receipt->pdf_document_id))->assertNotFound(); // another client never sees it
    }

    public function test_completion_re_reads_the_receipt_under_lock_so_a_concurrent_second_submit_is_refused(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$line]] = $this->asnWithLines($this->client()->id, $warehouse->id, [3]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 3, 'units' => [['unit_type' => 'carton', 'carton_qty' => 3]]], $this->location($warehouse, 'receiving'), $operator->id);
        $stale = GoodsReceipt::query()->firstOrFail(); // the second request's route-bound model: still `open` in memory

        // The first request commits in between (simulated by a direct UPDATE the in-memory model does not see).
        app(GoodsReceiptService::class)->complete(GoodsReceipt::query()->firstOrFail(), $operator->id, 'first click');
        $this->assertSame('open', $stale->status);
        $this->assertSame(1, Document::query()->where('type', 'goods_receipt')->count());

        try {
            app(GoodsReceiptService::class)->complete($stale, $operator->id, 'second click');
            $this->fail('the guards must run on the row re-read under lock, not on the stale model');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('warehouse.receipts.errors.not_open', ['no' => $stale->receipt_no]), $e->getMessage());
        }
        $fresh = $stale->fresh();
        $this->assertSame(['completed', 'first click'], [$fresh->status, $fresh->notes], 'the first completion is untouched');
        $this->assertSame(1, Document::query()->where('type', 'goods_receipt')->count(), 'no second goods_receipt document');
        $this->actingAs($operator)->post(route('warehouse.receipts.complete', $stale))->assertSessionHasErrors('complete');
    }

    public function test_completion_is_refused_while_no_cjk_font_is_configured_so_no_blank_pdf_is_filed(): void
    {
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $rcv = $this->location($warehouse, 'receiving');
        ['asn' => $asn, 'lines' => [$line]] = $this->asnWithLines($client->id, $warehouse->id, [2]);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 2, 'units' => [['unit_type' => 'carton', 'carton_qty' => 2]]], $rcv, $operator->id);
        $receipt = GoodsReceipt::query()->firstOrFail();

        $missing = storage_path('fonts/does-not-exist.ttf');
        config(['erp.pdf_cjk_font' => $missing]);
        $this->assertNull(app(GoodsReceiptService::class)->cjkFont());

        $this->actingAs($operator)->post(route('warehouse.receipts.complete', $receipt))->assertRedirect()->assertSessionHasErrors(['complete' => __('warehouse.receipts.errors.no_cjk_font', ['path' => $missing])]);
        $this->assertSame(['open', null], [$receipt->fresh()->status, $receipt->fresh()->pdf_document_id]);
        $this->assertSame(0, Document::query()->where('type', 'goods_receipt')->count());
        // The live preview still renders (DejaVu fallback) — it is not stored anywhere.
        $this->actingAs($operator)->get(route('warehouse.receipts.pdf', $receipt))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // 无预报收货 completes its batch in the same transaction, so the whole walk-in receipt is refused and nothing is created.
        $this->actingAs($operator)->post(route('warehouse.receiving.unplanned.store'), [
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'receiving_location_id' => $rcv->id,
            'rows' => [['description' => 'Walk-in', 'received_cartons' => 1, 'damaged_cartons' => 0, 'unit_type' => 'carton']],
        ])->assertSessionHasErrors('rows');
        $this->assertSame(1, Asn::query()->count());
        $this->assertSame(1, GoodsReceipt::query()->count());

        // Font back → the same receipt completes.
        config(['erp.pdf_cjk_font' => storage_path('fonts/cjk.ttf')]);
        app(GoodsReceiptService::class)->complete($receipt->fresh(), $operator->id);
        $this->assertSame('completed', $receipt->fresh()->status);
    }

    public function test_opening_a_batch_reads_the_asn_receipts_with_a_locking_read(): void
    {
        // Under REPEATABLE READ only a locking read sees a batch another operator committed while we waited on the ASN lock; a plain
        // SELECT returns the earlier snapshot and the second operator would build the same batch_no (duplicate key) instead of joining.
        Storage::fake('local');
        $operator = $this->staff('warehouse_operator');
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => [$l1, $l2]] = $this->asnWithLines($this->client()->id, $warehouse->id, [1, 1]);
        $rcv = $this->location($warehouse, 'receiving');

        DB::enableQueryLog();
        app(ReceivingService::class)->receiveLine($l1, ['received_cartons' => 1, 'units' => [['unit_type' => 'carton', 'carton_qty' => 1]]], $rcv, $operator->id);
        $sql = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $receiptReads = array_values(array_filter($sql, fn (string $q) => str_starts_with($q, 'select') && str_contains($q, '`goods_receipts`') && str_contains($q, '`asn_id`')));
        $this->assertNotEmpty($receiptReads);
        foreach ($receiptReads as $q) {
            $this->assertStringEndsWith('for update', $q, 'openFor() must read the ASN\'s receipts FOR UPDATE: '.$q);
        }
        $this->assertContains(true, array_map(fn (string $q) => str_contains($q, '`asns`') && str_ends_with($q, 'for update'), $sql), 'the ASN row is locked first');

        app(ReceivingService::class)->receiveLine($l2, ['received_cartons' => 1, 'units' => [['unit_type' => 'carton', 'carton_qty' => 1]]], $rcv, $operator->id);
        $this->assertSame([1], GoodsReceipt::query()->pluck('batch_no')->all(), 'the second line joins batch 1');
    }

    public function test_worklist_honours_an_explicit_all_warehouses_filter_over_the_session_warehouse(): void
    {
        $operator = $this->staff('warehouse_operator');
        $client = $this->client();
        $mel = $this->warehouse('MEL');
        $syd = $this->warehouse('SYD');
        ['asn' => $melAsn] = $this->asnWithLines($client->id, $mel->id, [4]);
        ['asn' => $sydAsn] = $this->asnWithLines($client->id, $syd->id, [6], 'loose_truck');
        $session = [WarehouseContext::SESSION_KEY => $mel->id];
        // The nav's warehouse switcher always marks the session warehouse `selected`, so the worklist dropdown is the 2nd occurrence.
        $selected = fn (int $warehouseId, string $html): int => substr_count($html, 'value="'.$warehouseId.'" selected');

        // First load: the session warehouse applies and is pre-selected in the worklist filter too.
        $first = $this->actingAs($operator)->withSession($session)->get(route('warehouse.receiving.index'));
        $first->assertOk()->assertSee($melAsn->asn_no)->assertDontSee($sydAsn->asn_no);
        $this->assertSame(2, $selected($mel->id, $first->getContent()));
        // 仓库 = 全部 submitted explicitly: every warehouse, and the dropdown must not claim otherwise (only the nav switcher stays on MEL).
        $all = $this->actingAs($operator)->withSession($session)->get(route('warehouse.receiving.index', ['warehouse_id' => '']));
        $all->assertOk()->assertSee($melAsn->asn_no)->assertSee($sydAsn->asn_no);
        $this->assertSame(1, $selected($mel->id, $all->getContent()));
        // Another warehouse chosen explicitly wins over the session warehouse.
        $other = $this->actingAs($operator)->withSession($session)->get(route('warehouse.receiving.index', ['warehouse_id' => $syd->id]));
        $other->assertOk()->assertSee($sydAsn->asn_no)->assertDontSee($melAsn->asn_no);
        $this->assertSame([1, 1], [$selected($syd->id, $other->getContent()), $selected($mel->id, $other->getContent())]);
    }

    /** @return array{asn: Asn, lines: array} */
    private function asnWithLines(int $clientId, int $warehouseId, array $expected, string $inboundType = 'container'): array
    {
        $asn = app(AsnService::class)->create(['client_id' => $clientId, 'warehouse_id' => $warehouseId, 'inbound_type' => $inboundType, 'containers' => $inboundType === 'container' ? [['container_no' => 'GR'.random_int(1000, 9999), 'size' => '40', 'unpack_mode' => 'loose']] : []]);
        $lines = app(AsnService::class)->addLines($asn, array_map(fn (int $qty, int $i) => ['consignment_mark' => 'GR-'.($i + 1), 'description' => 'Goods '.($i + 1).' of '.$asn->asn_no, 'expected_cartons' => $qty], $expected, array_keys($expected)));

        return ['asn' => $asn, 'lines' => $lines];
    }
}
