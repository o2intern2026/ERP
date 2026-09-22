<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-05 (CR #141): the bulk and walk-in receiving forms could not record 托盘来源 (so pallet rental / purchase never
 * billed) and a unit's dims / source / class could not be corrected afterwards. Now: a 托盘来源 select per row with 全部设为, and a
 * supervisor-only 修改单元信息 card on the unit page that re-suggests the pallet class, requires a reason and logs the change.
 */
class PalletSourceCorrectionTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_bulk_and_unplanned_receiving_record_the_pallet_source_per_row(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $location = $this->location($warehouse, 'receiving')->id;

        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$l1, $l2] = app(AsnService::class)->addLines($asn, [['description' => 'A', 'expected_cartons' => 20], ['description' => 'B', 'expected_cartons' => 6]]);

        $this->actingAs($operator)->get(route('warehouse.receiving.bulk_form', $asn))->assertOk()
            ->assertSee('name="rows[0][pallet_source]"', false)->assertSee('id="set-all-source"', false)->assertSee(__('warehouse.receiving.set_all'));
        $this->actingAs($operator)->post(route('warehouse.receiving.bulk_store', $asn), [
            'receiving_location_id' => $location, 'complete' => 0,
            'rows' => [
                ['include' => 1, 'asn_line_id' => $l1->id, 'received_cartons' => 20, 'unit_type' => 'pallet', 'unit_count' => 2, 'pallet_source' => 'chep'],
                ['include' => 1, 'asn_line_id' => $l2->id, 'received_cartons' => 6, 'unit_type' => 'pallet', 'unit_count' => 1, 'pallet_source' => 'warehouse_plain'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect(route('warehouse.asns.show', $asn));
        $this->assertSame(['chep', 'chep'], StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $l1->id)->pluck('pallet_source')->all());
        $this->assertSame(['warehouse_plain'], StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $l2->id)->pluck('pallet_source')->all());
        $this->actingAs($operator)->post(route('warehouse.receiving.bulk_store', $asn), ['receiving_location_id' => $location, 'rows' => [['include' => 1, 'asn_line_id' => $l1->id, 'received_cartons' => 1, 'unit_type' => 'pallet', 'pallet_source' => 'wooden']]])
            ->assertSessionHasErrors('rows.0.pallet_source');

        $this->actingAs($operator)->get(route('warehouse.receiving.unplanned.form'))->assertOk()->assertSee('name="rows[0][pallet_source]"', false)->assertSee('rows[__INDEX__][pallet_source]', false);
        $this->actingAs($operator)->post(route('warehouse.receiving.unplanned.store'), [
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'receiving_location_id' => $location,
            'rows' => [['description' => 'Walk-in', 'received_cartons' => 9, 'unit_type' => 'pallet', 'unit_count' => 3, 'pallet_source' => 'loscam']],
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['loscam', 'loscam', 'loscam'], StockUnit::query()->withoutGlobalScopes()->whereHas('asnLine', fn ($q) => $q->where('description', 'Walk-in'))->pluck('pallet_source')->all());
    }

    public function test_supervisor_corrects_a_units_source_dims_and_weight_with_a_reason_and_the_class_is_re_suggested(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $supervisor = $this->staff('warehouse_supervisor');
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Fast form', 'expected_cartons' => 10]]);
        $this->actingAs($operator)->post(route('warehouse.receiving.bulk_store', $asn), ['receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'rows' => [['include' => 1, 'asn_line_id' => $line->id, 'received_cartons' => 10, 'unit_type' => 'pallet', 'unit_count' => 1]]])->assertSessionHasNoErrors();
        $unit = StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $line->id)->sole();
        $this->assertSame(['client_own', null, null], [$unit->pallet_source, $unit->pallet_class, $unit->length_mm]);

        // A null class reads 未分类(按标准托计费), not POA; the card is for admin / supervisor only.
        $this->actingAs($supervisor)->get(route('warehouse.stock.show', $unit))->assertOk()
            ->assertSee(__('warehouse.stock.pallet_class_unclassified'))->assertDontSee(__('billing.rate_cards.poa'))->assertSee(__('warehouse.stock.correct.title'));
        $this->actingAs($operator)->get(route('warehouse.stock.show', $unit))->assertOk()->assertDontSee(__('warehouse.stock.correct.title'));
        $this->actingAs($operator)->patch(route('warehouse.stock.update', $unit), ['pallet_source' => 'chep', 'reason' => 'x'])->assertForbidden();

        // Reason required; nothing to change is refused.
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $unit), ['pallet_source' => 'chep'])->assertSessionHasErrors('reason');
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $unit), ['pallet_source' => 'client_own', 'reason' => 'same'])->assertSessionHasErrors(['correct' => __('warehouse.stock.correct.nothing')]);

        // Source + dims + weight: the class follows the client's thresholds (1200 × 1200 × 1700 mm → oversize_high on the Edward card), logged with the reason.
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $unit), ['pallet_source' => 'chep', 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1700, 'weight_kg' => 420, 'reason' => '收货时漏填'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $unit->refresh();
        $this->assertSame(['chep', 'oversize_high', 1200, 1200, 1700, '420.000', null], [$unit->pallet_source, $unit->pallet_class, $unit->length_mm, $unit->width_mm, $unit->height_mm, (string) $unit->weight_kg, $unit->pallet_class_overridden_reason]);
        $log = DB::table('activity_log')->where('log_name', 'stock_unit')->where('subject_id', $unit->id)->where('description', 'unit_corrected')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $props = json_decode($log->properties, true);
        $this->assertSame('收货时漏填', $props['reason']);
        $this->assertSame('chep', $props['attributes']['pallet_source']);
        $this->assertSame('client_own', $props['old']['pallet_source']);
        $this->assertSame((int) $supervisor->id, (int) $log->causer_id);

        // A lower height re-suggests standard; an explicit class overrides the suggestion and keeps the reason on the unit.
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $unit), ['pallet_source' => 'chep', 'height_mm' => 1300, 'reason' => '量错高度'])->assertSessionHasNoErrors();
        $this->assertSame('standard', $unit->fresh()->pallet_class);
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $unit), ['pallet_source' => 'chep', 'pallet_class' => 'oversize_wide', 'reason' => '实际超宽'])->assertSessionHasNoErrors();
        $this->assertSame(['oversize_wide', '实际超宽'], [$unit->fresh()->pallet_class, $unit->fresh()->pallet_class_overridden_reason]);
        $this->actingAs($supervisor)->get(route('warehouse.stock.show', $unit))->assertOk()->assertSee(__('warehouse.pallet_classes.oversize_wide'));

        // A carton unit takes dims / weight but no pallet source.
        [$cartons] = app(AsnService::class)->addLines($asn, [['description' => 'Loose', 'expected_cartons' => 3]]);
        $this->actingAs($operator)->post(route('warehouse.receiving.bulk_store', $asn), ['receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'rows' => [['include' => 1, 'asn_line_id' => $cartons->id, 'received_cartons' => 3, 'unit_type' => 'carton']]])->assertSessionHasNoErrors();
        $carton = StockUnit::query()->withoutGlobalScopes()->where('asn_line_id', $cartons->id)->sole();
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $carton), ['pallet_source' => 'chep', 'reason' => 'x'])->assertSessionHasErrors(['correct' => __('warehouse.stock.correct.not_pallet_source')]);
        $this->actingAs($supervisor)->patch(route('warehouse.stock.update', $carton), ['length_mm' => 400, 'width_mm' => 300, 'height_mm' => 300, 'weight_kg' => 12, 'reason' => '补量尺寸'])->assertSessionHasNoErrors();
        $this->assertSame([400, null], [$carton->fresh()->length_mm, $carton->fresh()->pallet_class]);
    }
}
