<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Support\Exceptions\RuleViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 INBOUND-02: the per-line receive path had no "already received" guard — browser Back + resubmit, a bookmarked form or
 * a slow double tap booked the line twice (a second set of units, a second receipt movement). ReceivingService::receiveLine now holds the
 * goods line FOR UPDATE and refuses; the form redirects to the ASN page with the reason; the three receiving forms lock their button on submit.
 */
class ReceivingDoubleReceiptTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_a_received_line_is_refused_on_resubmit_and_its_form_goes_back_to_the_asn_page(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Boxes', 'expected_cartons' => 5]]);
        $post = ['receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'received_cartons' => 5, 'damaged_cartons' => 0, 'units' => [['unit_type' => 'carton', 'carton_qty' => 5]]];

        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), $post)->assertRedirect(route('warehouse.asns.show', $asn))->assertSessionHasNoErrors();
        $this->assertSame(1, StockUnit::query()->withoutGlobalScopes()->count());

        // The resubmit: nothing new — no unit, no ledger receipt, no second 入库单 line — and the reason on the ASN page.
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), $post)->assertRedirect(route('warehouse.asns.show', $asn))
            ->assertSessionHasErrors(['receive' => __('warehouse.receiving.bulk.already_received', ['line' => $line->id])]);
        $this->assertSame(1, StockUnit::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, DB::table('stock_ledger')->where('movement_type', 'receipt')->count());
        $this->assertSame(1, DB::table('goods_receipt_lines')->where('asn_line_id', $line->id)->count());
        $this->assertSame(5, $line->fresh()->received_cartons);
        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertRedirect(route('warehouse.asns.show', $asn))
            ->assertSessionHasErrors(['receive' => __('warehouse.receiving.bulk.already_received', ['line' => $line->id])]);
        $this->actingAs($operator)->from(route('warehouse.receiving.form', [$asn, $line]))->get(route('warehouse.asns.show', $asn))->assertOk();

        // The service refuses in the same words whoever calls it (the bulk 入库单 path, a script).
        try {
            app(ReceivingService::class)->receiveLine($line->fresh(), ['received_cartons' => 5, 'units' => [['unit_type' => 'carton', 'carton_qty' => 5]]], $this->location($warehouse, 'receiving'));
            $this->fail('a received line must not be received again');
        } catch (RuleViolation $e) {
            $this->assertSame('warehouse.receiving.bulk.already_received', $e->langKey());
        }
        $this->assertSame(1, StockUnit::query()->withoutGlobalScopes()->count());
    }

    public function test_an_asn_past_receiving_refuses_the_form_and_the_post(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Late', 'expected_cartons' => 2]]);
        Asn::query()->whereKey($asn->id)->update(['status' => 'closed']);

        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertRedirect(route('warehouse.asns.show', $asn))
            ->assertSessionHasErrors(['receive' => __('warehouse.receiving.bulk.not_receivable')]);
        $this->actingAs($operator)->post(route('warehouse.receiving.store', [$asn, $line]), ['receiving_location_id' => $this->location($warehouse, 'receiving')->id, 'received_cartons' => 2, 'units' => [['unit_type' => 'carton', 'carton_qty' => 2]]])
            ->assertRedirect(route('warehouse.asns.show', $asn))->assertSessionHasErrors(['receive' => __('warehouse.receiving.bulk.not_receivable')]);
        $this->assertSame(0, StockUnit::query()->withoutGlobalScopes()->count());
    }

    public function test_the_three_receiving_forms_lock_their_submit_button_on_submit(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Boxes', 'expected_cartons' => 5]]);
        $lock = 'onsubmit="this.querySelector(\'button[type=submit]\').disabled = true"';

        $this->actingAs($operator)->get(route('warehouse.receiving.form', [$asn, $line]))->assertOk()->assertSee($lock, false);
        $this->actingAs($operator)->get(route('warehouse.receiving.bulk_form', $asn))->assertOk()->assertSee($lock, false);
        $this->actingAs($operator)->get(route('warehouse.receiving.unplanned.form'))->assertOk()->assertSee($lock, false);
    }
}
