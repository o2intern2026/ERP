<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-04 (CR #141): the per-line receiving form used to book 实收 95 on the 入库单 while the unit rows put 100 into stock.
 * Σ unit 箱数 must equal 实收箱数, and the variance reason the label calls 必填 is enforced like the bulk form does.
 */
class ReceivingUnitReconciliationTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_unit_rows_must_add_up_to_the_received_cartons_and_a_variance_needs_its_reason(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Kettles', 'expected_cartons' => 100, 'consignment_mark' => 'KT-1']]);
        $location = $this->location($warehouse, 'receiving')->id;

        // The form: rows come from a template (no cap of three) and the running total is on the page.
        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertOk()
            ->assertSee('id="unit-row-template"', false)->assertSee('id="unit-total"', false)->assertSee('units[__INDEX__][carton_qty]', false)
            ->assertDontSee('units[2][carton_qty]', false);

        // The audit repro: 实收 95, the untouched unit row still says 100 → refused, nothing booked.
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), [
            'receiving_location_id' => $location, 'received_cartons' => 95, 'variance_reason' => 'short shipped',
            'units' => [['unit_type' => 'carton', 'carton_qty' => 100]],
        ])->assertSessionHasErrors(['units' => __('warehouse.receiving.units_sum_mismatch', ['units' => 100, 'received' => 95])]);
        $this->assertSame(0, StockUnit::query()->withoutGlobalScopes()->count());
        $this->assertNull($line->fresh()->receiptLine);

        // Received ≠ expected without a reason → refused (the label said 必填, the controller did not).
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), [
            'receiving_location_id' => $location, 'received_cartons' => 95,
            'units' => [['unit_type' => 'carton', 'carton_qty' => 95]],
        ])->assertSessionHasErrors(['variance_reason' => __('warehouse.receiving.bulk.reason_required', ['line' => $line->id])])->assertSessionDoesntHaveErrors('units');
        $this->assertSame(0, StockUnit::query()->withoutGlobalScopes()->count());

        // Damage alone needs the reason too, even when received + damaged = expected.
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), [
            'receiving_location_id' => $location, 'received_cartons' => 98, 'damaged_cartons' => 2,
            'units' => [['unit_type' => 'carton', 'carton_qty' => 98]],
        ])->assertSessionHasErrors('variance_reason');

        // Two rows that add up, with the reason → booked; 入库单 and stock agree.
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), [
            'receiving_location_id' => $location, 'received_cartons' => 95, 'damaged_cartons' => 0, 'variance_reason' => '少 5 箱',
            'units' => [
                ['unit_type' => 'pallet', 'carton_qty' => 50, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => 400, 'pallet_source' => 'chep'],
                ['unit_type' => 'pallet', 'carton_qty' => 45, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'weight_kg' => 380, 'pallet_source' => 'chep'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('warehouse.asns.show', $asn));
        $units = StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $line->id)->get();
        $this->assertSame(95, (int) $units->sum('qty_on_hand'));
        $this->assertSame(95, (int) $line->fresh()->receiptLine->received_cartons);
        $this->assertSame(['chep', 'chep'], $units->pluck('pallet_source')->all());

        // A line that matches its pre-advice needs no reason (unchanged behaviour).
        [$exact] = app(AsnService::class)->addLines($asn, [['description' => 'Cups', 'expected_cartons' => 4]]);
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $exact]), [
            'receiving_location_id' => $location, 'received_cartons' => 4, 'units' => [['unit_type' => 'carton', 'carton_qty' => 4]],
        ])->assertSessionHasNoErrors();
    }
}
