<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** Tester feedback #4 (2026-09-10): the 入库单 can be filled in by hand for the whole ASN, and hidden spare unit rows no longer block per-line receiving. */
class BulkReceivingTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_supervisor_receives_the_whole_asn_from_one_screen_and_completes_the_receipt(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        [$a, $b, $c] = app(AsnService::class)->addLines($asn, [
            ['description' => 'Chairs', 'expected_cartons' => 12, 'consignment_mark' => 'CHAIR-1'],
            ['description' => 'Tables', 'expected_cartons' => 4, 'consignment_mark' => 'TABLE-1'],
            ['description' => 'Lamps', 'expected_cartons' => 6, 'consignment_mark' => 'LAMP-1'],
        ]);
        $location = $this->location($warehouse, 'receiving');

        // The ASN page offers the manual receipt and explains where the 入库单 comes from.
        $this->actingAs($supervisor)->get(route('warehouse.asns.show', $asn))->assertOk()
            ->assertSee(route('warehouse.receiving.bulk_form', $asn))->assertSee(__('warehouse.asns.receipts_cta'))->assertSee(__('warehouse.asns.import'));
        $this->actingAs($supervisor)->get(route('warehouse.receiving.bulk_form', $asn))->assertOk()->assertSee('CHAIR-1')->assertSee('TABLE-1')->assertSee('LAMP-1');

        // A variance without a reason is refused with a Chinese message; nothing is written.
        $this->actingAs($supervisor)->post(route('warehouse.receiving.bulk_store', $asn), [
            'receiving_location_id' => $location->id, 'complete' => 1,
            'rows' => [['include' => 1, 'asn_line_id' => $a->id, 'received_cartons' => 10, 'damaged_cartons' => 0, 'unit_type' => 'carton', 'unit_count' => 1]],
        ])->assertSessionHasErrors('rows.0.variance_reason');
        $this->assertSame(0, GoodsReceipt::query()->withoutGlobalScopes()->count());

        // Two of three lines ticked (one short with a reason), third left for later; complete the batch at once.
        $this->actingAs($supervisor)->post(route('warehouse.receiving.bulk_store', $asn), [
            'receiving_location_id' => $location->id, 'complete' => 1, 'delivery_reference' => 'COSU-1',
            'rows' => [
                ['include' => 1, 'asn_line_id' => $a->id, 'received_cartons' => 10, 'damaged_cartons' => 0, 'variance_reason' => 'short shipped', 'unit_type' => 'pallet', 'unit_count' => 2],
                ['include' => 1, 'asn_line_id' => $b->id, 'received_cartons' => 4, 'damaged_cartons' => 0, 'unit_type' => 'carton', 'unit_count' => 1],
                ['asn_line_id' => $c->id, 'received_cartons' => 6, 'unit_type' => 'carton'], // not ticked
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $receipt = GoodsReceipt::query()->withoutGlobalScopes()->sole();
        $this->assertSame([$asn->asn_no.'-R1', 'completed', 2, 14, 'COSU-1'], [$receipt->receipt_no, $receipt->status, $receipt->lines()->count(), (int) $receipt->received_cartons, $receipt->delivery_reference]);
        $this->assertSame(3, StockUnit::query()->withoutGlobalScopes()->where('goods_receipt_id', $receipt->id)->count()); // 2 pallets of 5 + 1 carton unit of 4
        $this->assertNotNull($receipt->pdf_document_id);
        $this->assertTrue($a->fresh()->isReceived());
        $this->assertFalse($c->fresh()->isReceived());
        $this->assertNull($asn->fresh()->receiving_completed_at);

        // The third line later → a second batch; receiving it again is refused.
        $this->actingAs($supervisor)->post(route('warehouse.receiving.bulk_store', $asn), [
            'receiving_location_id' => $location->id,
            'rows' => [['include' => 1, 'asn_line_id' => $c->id, 'received_cartons' => 6, 'damaged_cartons' => 0, 'unit_type' => 'carton']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('warehouse.asns.show', $asn));
        $this->assertSame($asn->asn_no.'-R2', GoodsReceipt::query()->withoutGlobalScopes()->where('status', 'open')->value('receipt_no'));
        $this->actingAs($supervisor)->get(route('warehouse.receiving.bulk_form', $asn))->assertOk()->assertSee(__('warehouse.receiving.bulk.nothing'));
        $this->actingAs($this->staff('customer_service'))->get(route('warehouse.receiving.bulk_form', $asn))->assertForbidden();
    }

    public function test_per_line_receiving_ignores_the_hidden_spare_unit_rows(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Boxes', 'expected_cartons' => 1, 'consignment_mark' => 'BOX-1']]);

        // Exactly what the browser sent on 2026-09-10: one filled row plus two untouched spare rows whose selects carry defaults.
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), [
            'receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'received_cartons' => 1, 'damaged_cartons' => 0,
            'units' => [
                ['unit_type' => 'pallet', 'carton_qty' => 1, 'length_mm' => 120, 'width_mm' => 120, 'height_mm' => 120, 'weight_kg' => 30, 'pallet_source' => 'client_own', 'pallet_class' => ''],
                ['unit_type' => 'pallet', 'carton_qty' => '', 'length_mm' => '', 'width_mm' => '', 'height_mm' => '', 'weight_kg' => '', 'pallet_source' => '', 'pallet_class' => ''],
                ['unit_type' => 'pallet', 'carton_qty' => '', 'length_mm' => '', 'width_mm' => '', 'height_mm' => '', 'weight_kg' => '', 'pallet_source' => '', 'pallet_class' => ''],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('warehouse.asns.show', $asn));
        $this->assertSame(1, StockUnit::query()->withoutGlobalScopes()->count());
        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertOk(); // form still renders (line now received → shows form anyway)
    }
}
