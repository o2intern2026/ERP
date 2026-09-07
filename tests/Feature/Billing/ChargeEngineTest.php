<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Modules\Warehouse\Services\TaskService;
use App\Support\Contracts\JobService;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** A6a end to end: WMS events → charges with snapshots; idempotent; POA → review; missing rate → exception; redo → reversal (§6.8 #2 #3 #8 #9). */
class ChargeEngineTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_devanning_and_putaway_each_produce_exactly_one_charge_at_the_card_price(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'containers' => [['container_no' => 'BILL40', 'size' => '40', 'unpack_mode' => 'pallet', 'gross_weight_kg' => 15000]]]);
        $container = $asn->containers()->first();
        [$line] = app(AsnService::class)->addLines($asn, [['container_no' => 'BILL40', 'description' => 'Goods', 'expected_cartons' => 40]]);
        $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 40, 'units' => [
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'warehouse_plain'],
            ['unit_type' => 'pallet', 'carton_qty' => 20, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1300, 'weight_kg' => 400, 'pallet_source' => 'client_own'],
        ]], $this->location($warehouse, 'receiving'));
        foreach ($units as $u) {
            app(PutawayService::class)->putaway($u, $this->location($warehouse, 'storage'));
        }
        $task = app(TaskService::class)->create('devanning', ['job_id' => $asn->job_id, 'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'source_type' => 'container', 'source_id' => $container->id, 'asn_id' => $asn->id, 'container_id' => $container->id]);
        app(TaskService::class)->complete($task, ['billable_qty' => 1, 'billable_uom' => 'container'], $asn->asn_no);

        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue(); // a second run must not double-charge

        $charges = Charge::query()->with('chargeCode')->get()->keyBy(fn (Charge $c) => $c->chargeCode->code);
        $this->assertSame(28000, $charges['WH-DEVAN-40-PLT']->amount_cents); // §6.8 #2
        $this->assertSame(900, $charges['WH-PUTAWAY-PLT']->amount_cents);   // 2 pallets × 4.50
        $this->assertSame(60, $charges['WH-LABEL-IN']->amount_cents);       // 2 labels × 0.30
        $this->assertSame(2500, $charges['VAS-PALLET-PURCHASE']->amount_cents); // one warehouse-supplied pallet (§6.8 #13)
        $this->assertCount(4, $charges);
        $this->assertSame($asn->job_id, $charges['WH-PUTAWAY-PLT']->job_id);
        $this->assertSame('task', $charges['WH-DEVAN-40-PLT']->source_type);
        $this->assertSame("task:{$task->id}", $charges['WH-DEVAN-40-PLT']->source_activity_id);
        $this->assertSame('standard', $charges['WH-DEVAN-40-PLT']->calculation_snapshot_json['card']);
        $this->assertSame('pending', $charges['WH-DEVAN-40-PLT']->status);
        $this->assertSame('gst_10', $charges['WH-DEVAN-40-PLT']->tax_treatment);
    }

    public function test_mixed_devanning_is_poa_and_a_missing_rate_raises_an_exception_without_a_charge(): void
    {
        $client = $this->client();
        $engine = app(ChargeEngine::class);
        $job = app(JobService::class)->create($client->id, 'container')['job_id'];

        $engine->applyEvent(['event_name' => 'task.completed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => ['task_id' => 501, 'task_type' => 'devanning', 'billable_qty' => 1, 'container' => ['size' => '20', 'unpack_mode' => 'mixed', 'line_count' => 5]]]);
        $this->assertDatabaseHas('charges', ['source_activity_id' => 'task:501', 'status' => 'needs_review', 'amount_cents' => 0]);

        $client->update(['standard_rate_card_id' => null]);
        $charges = $engine->applyEvent(['event_name' => 'task.completed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => ['task_id' => 502, 'task_type' => 'labour', 'hours_business' => 2, 'hours_after_hours' => 0]]);
        $this->assertSame([], $charges);
        $this->assertDatabaseMissing('charges', ['source_activity_id' => 'task:502']);
        $this->assertDatabaseHas('exceptions', ['type' => 'missing_rate', 'source_module' => 'billing', 'source_type' => 'task', 'source_id' => 502, 'client_id' => $client->id]); // §6.8 #9
    }

    public function test_outbound_packed_produces_despatch_and_banded_pick_charges_and_a_redo_reverses(): void
    {
        $client = $this->client();
        $engine = app(ChargeEngine::class);
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $payload = fn (int $version) => ['event_name' => 'outbound.packed', 'job_id' => $job, 'client_id' => $client->id, 'payload' => [
            'order_id' => 77, 'fulfilment_id' => 9, 'activity_version' => $version, 'is_urgent' => false, 'label_count' => 4,
            'lines' => [['unit_type' => 'pallet', 'qty' => 1, 'unit_weight_kg' => 400], ['unit_type' => 'carton', 'qty' => 3, 'unit_weight_kg' => 10], ['unit_type' => 'carton', 'qty' => 2, 'unit_weight_kg' => 30], ['unit_type' => 'carton', 'qty' => 1, 'unit_weight_kg' => 50]],
        ]];

        $engine->applyEvent($payload(1));
        $byCode = fn () => Charge::query()->with('chargeCode')->where('status', '!=', 'reversed')->whereNull('reversal_of_charge_id')->get()->mapWithKeys(fn (Charge $c) => [$c->chargeCode->code => $c->amount_cents]);
        $this->assertEquals(['WH-ORDER-DESPATCH' => 500, 'WH-PICK-PLT' => 400, 'WH-PICK-CTN-LT22' => 450, 'WH-PICK-CTN-22-45' => 700, 'WH-PICK-CTN-GE45' => 450, 'WH-LABEL-OUT' => 120], $byCode()->all()); // §6.8 #3, §4.7 #22
        $this->assertDatabaseMissing('charges', ['charge_code_id' => ChargeCode::query()->where('code', 'WH-ORDER-DESPATCH-URGENT')->value('id')]);
        $this->assertDatabaseMissing('exceptions', ['type' => 'missing_rate']);

        // Pack undone and redone (activity_version 2): old charges reversed with negative twins, new ones created — §6.8 #9.
        $engine->applyEvent($payload(2));
        $this->assertSame(6, Charge::query()->where('status', 'reversed')->whereNull('reversal_of_charge_id')->count()); // originals
        $this->assertSame(6, Charge::query()->whereNotNull('reversal_of_charge_id')->where('status', 'reversed')->count()); // audit-only twins (never invoiced)
        $this->assertSame(-500, Charge::query()->whereNotNull('reversal_of_charge_id')->whereHas('chargeCode', fn ($q) => $q->where('code', 'WH-ORDER-DESPATCH'))->value('amount_cents'));
        $this->assertSame(6, Charge::query()->where('activity_version', 2)->count());
        $this->assertSame(500 + 400 + 450 + 700 + 450 + 120, (int) Charge::query()->where('status', '!=', 'reversed')->sum('amount_cents')); // net effect: charged once

        // CHANGE_REQUESTS #5 (project lead 2026-09-08): an urgent order pays the $5 standard fee AND the $15 urgent fee.
        $urgent = $payload(1);
        $urgent['payload'] = ['order_id' => 78, 'fulfilment_id' => 10, 'activity_version' => 1, 'is_urgent' => true, 'label_count' => 1, 'lines' => [['unit_type' => 'carton', 'qty' => 1, 'unit_weight_kg' => 10]]];
        $engine->applyEvent($urgent);
        $urgentCharges = Charge::query()->with('chargeCode')->where('source_activity_id', 'like', 'fulfilment:10%')->get()->mapWithKeys(fn (Charge $c) => [$c->chargeCode->code => $c->amount_cents]);
        $this->assertSame(500, $urgentCharges['WH-ORDER-DESPATCH']);
        $this->assertSame(1500, $urgentCharges['WH-ORDER-DESPATCH-URGENT']);
        $this->assertSame(2, Charge::query()->where('source_activity_id', 'like', 'fulfilment:10%')->whereHas('chargeCode', fn ($q) => $q->where('category', 'warehouse')->where('code', 'like', 'WH-ORDER-%'))->count());
    }

    public function test_quote_confirmed_charges_freight_tailgate_and_fuel_once_from_a_client_card(): void
    {
        $client = $this->client(['default_markup_percent' => 20]);
        $job = app(JobService::class)->create($client->id, 'transport_only')['job_id'];
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-TAILGATE'], 'pricing_mode' => 'fixed', 'rate_cents' => 4500]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 10]);

        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([
            'shipment_id' => 15, 'shipment_no' => 'SHP-TEST-15', 'shipment_type' => 'outbound', 'order_id' => 77, 'client_id' => $client->id, 'job_id' => $job, 'source' => 'transdirect', 'pricing_mode' => 'cost_plus',
            'cost_cents' => 10000, 'customer_price_cents' => 12000, 'tailgate_required' => true, 'zone' => 'metro', 'packages' => ['count' => 2, 'total_weight_kg' => 40, 'total_cbm' => 0.3], 'confirmed_by_type' => 'coordinator',
        ], 'shipment.quote_confirmed', $job, $client->id)));
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();

        $byCode = Charge::query()->with('chargeCode')->get()->mapWithKeys(fn (Charge $c) => [$c->chargeCode->code => $c->amount_cents]);
        $this->assertEquals(['TR-DELIVERY-BASE' => 12000, 'TR-TAILGATE' => 4500, 'TR-FUEL' => 1200], $byCode->all()); // §6.8 #8: three codes, once each
    }
}
