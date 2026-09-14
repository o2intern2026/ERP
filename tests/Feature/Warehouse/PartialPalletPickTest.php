<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Billing\Models\Charge;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderEstimateService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Lead decision 2026-09-14 (CHANGE_REQUESTS #121): a pallet holding a dozen cartons is sold one carton at a time. Taking part of
 * the pallet is carton picks, banded on the pallet weight spread over its cartons; only a pallet that leaves whole is a pallet
 * pick. The packed event (Billing) and the estimate follow the same rule. Before this, one carton off a pallet unit was billed
 * as a whole-pallet pick.
 */
class PartialPalletPickTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_cartons_taken_off_a_pallet_are_carton_picks_and_the_pallet_that_leaves_whole_is_a_pallet_pick(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        // One pallet of 20 cartons, 400 kg → 20 kg per carton (the ASN line weight matches, so both weight paths agree).
        ['asn' => $asn, 'lines' => [$asnLine]] = $this->stockedAsn($client, $warehouse, [[
            'mark' => 'PLT-20', 'cartons' => 20, 'weight_kg' => 400,
            'units' => [['unit_type' => 'pallet', 'carton_qty' => 20, 'weight_kg' => 400, 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 1200]],
        ]]);
        $five = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => 5, 'weight_kg' => 100]]);
        $fifteen = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLine->id, 'qty' => 15, 'weight_kg' => 300]]);
        $this->assertSame(['allocated', 'allocated'], [$five->operational_status, $fifteen->operational_status]);

        // Estimate: 5 off a 20-carton pallet = 5 carton picks at 20 kg (< 22 kg band), no pallet pick, no pallet load-out.
        $estimates = app(OrderEstimateService::class);
        $codes = fn (array $lines) => collect($lines)->mapWithKeys(fn ($l) => [$l['charge_code'] => (float) $l['qty']])->all();
        $this->assertSame(['WH-ORDER-DESPATCH' => 1.0, 'WH-PICK-CTN-LT22' => 5.0, 'WH-LABEL-OUT' => 5.0], $codes($estimates->lines($five)));
        $this->assertSame(['WH-ORDER-DESPATCH' => 1.0, 'WH-PICK-CTN-LT22' => 15.0, 'WH-LABEL-OUT' => 15.0], $codes($estimates->lines($fifteen)));

        // Pick + pack the 5: the packed event says carton × 5 at 20 kg — Billing bands it as five < 22 kg picks.
        $outbound = app(OutboundService::class);
        $this->pickAndPack($outbound, $warehouse->id, $five->id, $operator->id);
        $packed = OutboxEvent::query()->where('event_name', 'outbound.packed')->latest('id')->firstOrFail();
        $this->assertSame(['carton', 5, 20.0, 0, 5], [
            $packed->payload['lines'][0]['unit_type'], $packed->payload['lines'][0]['qty'], (float) (float) $packed->payload['lines'][0]['unit_weight_kg'], $packed->payload['pallet_count'], $packed->payload['carton_count'],
        ]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(['WH-LABEL-OUT' => 30, 'WH-ORDER-DESPATCH' => 500, 'WH-PICK-CTN-LT22' => 750], $this->chargesFor($five->id));

        // The remaining 15 leave with the pallet → one pallet pick at the pallet's weight, no carton picks.
        $this->pickAndPack($outbound, $warehouse->id, $fifteen->id, $operator->id);
        $packed = OutboxEvent::query()->where('event_name', 'outbound.packed')->latest('id')->firstOrFail();
        $this->assertSame(['pallet', 15, 400.0, 1, 0], [
            $packed->payload['lines'][0]['unit_type'], $packed->payload['lines'][0]['qty'], (float) (float) $packed->payload['lines'][0]['unit_weight_kg'], $packed->payload['pallet_count'], $packed->payload['carton_count'],
        ]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(['WH-LABEL-OUT' => 30, 'WH-ORDER-DESPATCH' => 500, 'WH-PICK-PLT' => 400], $this->chargesFor($fifteen->id));
    }

    public function test_estimate_splits_a_line_into_whole_pallets_plus_loose_cartons(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        // Two pallets of 20 → an order for 45 is two pallet picks plus five carton picks (45 > stock is fine for an estimate: nothing is reserved here).
        ['asn' => $asn, 'lines' => [$asnLine]] = $this->stockedAsn($client, $warehouse, [[
            'mark' => 'PLT-40', 'cartons' => 40, 'weight_kg' => 800,
            'units' => [['unit_type' => 'pallet', 'carton_qty' => 20, 'weight_kg' => 400], ['unit_type' => 'pallet', 'carton_qty' => 20, 'weight_kg' => 400]],
        ]]);
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock', 'external_ref' => 'EST-45',
            'deliver_to_name' => 'Receiver Pty Ltd', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 45, 'asn_line_id' => $asnLine->id, 'actual_weight_kg' => 900]],
        ], null, 'manual');

        $lines = collect(app(OrderEstimateService::class)->lines($order))->mapWithKeys(fn ($l) => [$l['charge_code'] => (float) $l['qty']])->all();
        $this->assertSame(['WH-ORDER-DESPATCH' => 1.0, 'WH-PICK-PLT' => 2.0, 'WH-PICK-CTN-LT22' => 5.0, 'WH-LABEL-OUT' => 7.0, 'WH-LOAD-PLT' => 2.0], $lines);
    }

    private function pickAndPack(OutboundService $outbound, int $warehouseId, int $orderId, int $operatorId): void
    {
        $task = $outbound->releaseWave($warehouseId, ['order_ids' => [$orderId]], $operatorId)['tasks']->sole();
        foreach ($task->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $operatorId);
        }
        $fulfilment = DB::table('fulfilments')->where('order_id', $orderId)->firstOrFail();
        $outbound->pack($fulfilment->id, [['package_type' => 'carton', 'weight_kg' => 20, 'length_mm' => 600, 'width_mm' => 400, 'height_mm' => 300]], $operatorId);
    }

    /** @return array<string, int> charge code → amount for the order's fulfilment */
    private function chargesFor(int $orderId): array
    {
        $fulfilmentId = (int) DB::table('fulfilments')->where('order_id', $orderId)->value('id');

        return Charge::query()->with('chargeCode')->where('source_activity_id', 'fulfilment:'.$fulfilmentId)->get()
            ->mapWithKeys(fn (Charge $c) => [$c->chargeCode->code => (int) $c->amount_cents])->sortKeys()->all();
    }
}
