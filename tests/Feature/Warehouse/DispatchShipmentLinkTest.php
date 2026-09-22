<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\OutboundDispatch;
use App\Modules\Warehouse\Services\OutboundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Audit 2026-09-22 OUTBOUND-03 (CR #141): the handover's only link to the TMS shipment was a hand-typed numeric id nobody could see. The board
 * now shows carrier · shipment number · status per 待发运 row (read-only from Transport's tables), links a booked shipment automatically with the
 * handover party the quote source implies, says 未订舱 otherwise, keeps a manual override and refuses an id of another batch.
 */
class DispatchShipmentLinkTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_board_shows_the_shipment_inline_prefills_a_booked_one_and_refuses_a_foreign_id(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'SH1', 'cartons' => 20, 'weight_kg' => 100]]);
        $booked = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 4]]);
        $quoting = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 2]]);
        $outbound = app(OutboundService::class);
        $pack = function ($order) use ($outbound, $warehouse, $operator): int {
            $fulfilment = (int) DB::table('fulfilments')->where('order_id', $order->id)->value('id');
            ['tasks' => $tasks] = $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id);
            foreach ($tasks->first()->lines as $l) {
                $outbound->confirmPick($l, $l->required_qty, $operator->id);
            }
            $outbound->pack($fulfilment, [['package_type' => 'carton', 'weight_kg' => 8, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 300]], $operator->id);
            app(OutboxDispatcher::class)->dispatchDue(); // outbound.packed → Transport stamps fulfilment_id on the order's shipment

            return $fulfilment;
        };
        $fulfilmentBooked = $pack($booked);
        $fulfilmentQuoting = $pack($quoting);

        // Transport's own shipments (created by its order.confirmed consumer); one is booked with a carrier, the other still quoting.
        $carrierId = DB::table('carriers')->insertGetId(['code' => 'ACME', 'name' => 'Acme Freight', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $shipmentBooked = DB::table('shipments')->where('fulfilment_id', $fulfilmentBooked)->first();
        $shipmentQuoting = DB::table('shipments')->where('fulfilment_id', $fulfilmentQuoting)->first();
        $this->assertNotNull($shipmentBooked, 'Transport should have a shipment per fulfilment');
        DB::table('shipments')->where('id', $shipmentBooked->id)->update(['status' => 'booked', 'carrier_id' => $carrierId]);
        DB::table('shipments')->where('id', $shipmentQuoting->id)->update(['status' => 'quoting']);

        $page = $this->actingAs($operator)->get(route('warehouse.outbound.index'))->assertOk();
        $page->assertSee($shipmentBooked->shipment_no)->assertSee('Acme Freight')->assertSee(__('transport.statuses.booked'))
            ->assertSee('<input type="hidden" name="shipment_id" value="'.$shipmentBooked->id.'"', false)
            ->assertSee($shipmentQuoting->shipment_no)->assertSee(__('warehouse.outbound.not_booked'))->assertSee(__('warehouse.outbound.manual_shipment_id'))
            ->assertDontSee('<input type="hidden" name="shipment_id" value="'.$shipmentQuoting->id.'"', false);

        // A wrong id (the other batch's shipment) is refused before anything is written.
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilmentBooked), ['pallet_count' => 0, 'handed_to' => 'carrier', 'shipment_id' => $shipmentQuoting->id])
            ->assertSessionHasErrors(['pallet_count' => __('warehouse.outbound.errors.shipment_not_of_fulfilment', ['id' => $shipmentQuoting->id])]);
        $this->assertSame(0, OutboundDispatch::query()->count());

        // The prefilled id goes through and lands on the dispatch + event; the unbooked batch dispatches without a shipment (客户自提).
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilmentBooked), ['pallet_count' => 0, 'handed_to' => 'carrier', 'shipment_id' => $shipmentBooked->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame((int) $shipmentBooked->id, (int) OutboundDispatch::query()->where('fulfilment_id', $fulfilmentBooked)->value('shipment_id'));
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'outbound.dispatched', 'payload->shipment_id' => $shipmentBooked->id]);
        $this->actingAs($operator)->post(route('warehouse.outbound.dispatch', $fulfilmentQuoting), ['pallet_count' => 0, 'handed_to' => 'client'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(OutboundDispatch::query()->where('fulfilment_id', $fulfilmentQuoting)->value('shipment_id'));
    }
}
