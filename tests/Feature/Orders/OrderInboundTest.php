<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderEvent;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderInboundService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 从订单生成预报单 (lead decision 2026-09-11, CHANGE_REQUESTS #117): the client's orders come first; staff pick the ones that arrive
 * together and one ASN is opened for them through Warehouse's InboundService, linked line by line, under one shared Job. Goods
 * arriving then flow through the unchanged receive → putaway → allocation chain, so the orders reach 待释放 on their own.
 */
class OrderInboundTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** A portal-style order: no Job given → its own loose Job; goods lines not linked to any ASN. */
    private function order(int $clientId, string $mark, array $lines): Order
    {
        return app(OrderCreationService::class)->create([
            'client_id' => $clientId, 'order_type' => 'from_stock', 'consignment_mark' => $mark, 'fba_reference' => 'FBA-'.$mark,
            'deliver_to_name' => 'Shop '.$mark, 'deliver_to_phone' => '0400 000 000', 'deliver_to_address' => '1 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDays(14)->toDateString(), 'service_level' => 'standard',
            'lines' => array_map(fn (array $l) => ['description_cn' => $l['cn'] ?? null, 'description_en' => $l['en'], 'package_type' => 'carton', 'carton_qty' => $l['qty'], 'actual_weight_kg' => $l['kg'] ?? null], $lines),
        ], null, 'portal');
    }

    public function test_staff_open_one_asn_for_the_orders_that_arrive_together_and_putaway_allocates_them(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $a = $this->order($client->id, 'MK-A', [['cn' => '蓝牙音箱', 'en' => 'Bluetooth speaker', 'qty' => 10, 'kg' => 8.5], ['en' => 'Kettle', 'qty' => 4]]);
        $b = $this->order($client->id, 'MK-B', [['en' => 'Mugs', 'qty' => 6]]);
        $foreign = $this->order($this->client()->id, 'MK-Z', [['en' => 'Plates', 'qty' => 1]]);
        $this->assertNotSame($a->job_id, $b->job_id, 'portal orders open their own loose Jobs');
        app(OrderStatusService::class)->transitionOperational($a, 'confirmed', $cs->id);
        app(OutboxDispatcher::class)->dispatchDue(); // order.confirmed with no ASN line behind it → nothing reserved
        $this->assertSame('confirmed', $a->fresh()->operational_status);

        // The worklist and who may use it; the order page points at it.
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()
            ->assertSee($a->order_no)->assertSee($b->order_no)->assertSee($foreign->order_no)->assertSee(__('orders.inbound.submit'));
        $this->actingAs($cs)->get(route('orders.inbound.index', ['client_id' => $client->id]))->assertOk()->assertSee($a->order_no)->assertDontSee($foreign->order_no);
        $this->actingAs($this->staff('warehouse_supervisor'))->get(route('orders.inbound.index'))->assertOk();
        $this->actingAs($this->staff('dispatcher'))->get(route('orders.inbound.index'))->assertForbidden();
        $this->actingAs($cs)->get(route('orders.show', $a))->assertOk()->assertSee('orders/inbound?client_id=', false); // the 生成预报单 button

        // Two clients at once are refused in Chinese; nothing is created.
        $this->actingAs($cs)->from(route('orders.inbound.index'))
            ->post(route('orders.inbound.store'), ['order_ids' => [$a->id, $foreign->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck'])
            ->assertRedirect(route('orders.inbound.index'))->assertSessionHasErrors(['inbound' => __('orders.inbound.errors.mixed_clients')]);
        $this->assertSame(0, Asn::query()->count());

        // Generate: one ASN under A's Job; B merged into it and B's emptied Job cancelled; every line linked both ways; A's lines on backorder.
        $bOldJobId = $b->job_id;
        $response = $this->actingAs($cs)->post(route('orders.inbound.store'), [
            'order_ids' => [$a->id, $b->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container',
            'container_no' => 'MSKU1234567', 'container_size' => '40', 'unpack_mode' => 'loose', 'expected_date' => today()->addDays(7)->toDateString(), 'notes' => '周五到港',
        ]);
        $asn = Asn::query()->sole();
        $response->assertRedirect(route('warehouse.asns.show', $asn));
        $a->refresh();
        $b->refresh();
        $this->assertSame([$a->job_id, $a->job_id, 'coordinator', 'booked', 'container', $warehouse->id], [$asn->job_id, $b->job_id, $asn->created_by_type, $asn->status, $asn->inbound_type, $asn->warehouse_id]);
        $this->assertSame('cancelled', Job::query()->findOrFail($bOldJobId)->operational_status);
        $this->assertSame('open', Job::query()->findOrFail($a->job_id)->operational_status);
        $this->assertSame(['MSKU1234567', 3], [$asn->containers()->value('container_no'), (int) $asn->containers()->value('line_count')]);
        $this->assertStringContainsString($a->order_no, (string) $asn->notes);
        $this->assertStringContainsString('周五到港', (string) $asn->notes);
        $lines = $asn->lines()->orderBy('id')->get();
        $this->assertCount(3, $lines);
        $this->assertSame(['MK-A', '蓝牙音箱 / Bluetooth speaker', 10, 'Shop MK-A', 'Richmond', 'FBA-MK-A', '8.500'], [$lines[0]->consignment_mark, $lines[0]->description, $lines[0]->expected_cartons, $lines[0]->deliver_to_name, $lines[0]->deliver_to_suburb, $lines[0]->fba_reference, $lines[0]->weight_kg]);
        $this->assertSame($asn->containers()->value('id'), $lines[0]->container_id);
        foreach ([$a, $b] as $order) {
            foreach ($order->lines()->get() as $line) {
                $asnLine = $lines->firstWhere('order_line_id', $line->id);
                $this->assertNotNull($asnLine, "order line {$line->id} has its ASN line");
                $this->assertSame($asnLine->id, $line->asn_line_id);
                $this->assertSame($order->operational_status === 'confirmed' ? $line->carton_qty : 0, $line->qty_backordered);
            }
        }
        $this->assertTrue(OrderEvent::query()->where('order_id', $a->id)->where('note', 'like', '%'.$asn->asn_no.'%')->exists());
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk() // rows are gone (the flash still names the merged order)
            ->assertDontSee(route('orders.show', $a).'"', false)->assertDontSee(route('orders.show', $b).'"', false)->assertSee(route('orders.show', $foreign).'"', false);
        $this->actingAs($cs)->get(route('orders.show', $a))->assertOk()->assertSee($asn->asn_no)->assertDontSee('orders/inbound?client_id=', false); // button gone (the timeline note also says 生成预报单)
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee('MSKU1234567');
        $this->assertSame(1, app(OrderInboundService::class)->pendingCount(), 'only the other client\'s order is still waiting');

        // Goods arrive: receive + put away every line → asn.putaway_completed → the confirmed order is allocated; the other waits for confirmation.
        $operator = $this->staff('warehouse_operator');
        $this->actingAs($operator);
        foreach ($lines as $line) {
            $units = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $line->expected_cartons, 'units' => [['unit_type' => 'carton', 'carton_qty' => $line->expected_cartons]]], $this->location($warehouse, 'receiving'), $operator->id);
            foreach ($units as $unit) {
                app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));
            }
        }
        $this->assertSame('putaway', $asn->fresh()->status);
        app(OutboxDispatcher::class)->dispatchDue(); // asn.putaway_completed → Orders allocates the backorders → stock.reserved
        app(OutboxDispatcher::class)->dispatchDue();
        $a->refresh();
        $this->assertSame('allocated', $a->operational_status);
        $this->assertSame(0, (int) $a->lines()->sum('qty_backordered'));
        $this->assertSame('received', $b->fresh()->operational_status);

        app(OrderStatusService::class)->transitionOperational($b->fresh(), 'confirmed', $cs->id);
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('allocated', $b->fresh()->operational_status);
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertDontSee(__('warehouse.asns.generate_orders'));
    }

    public function test_generation_guards_status_links_and_jobs_that_already_carry_an_asn(): void
    {
        $cs = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $a = $this->order($client->id, 'MK-A', [['en' => 'Kettle', 'qty' => 4]]);
        $b = $this->order($client->id, 'MK-B', [['en' => 'Mugs', 'qty' => 6]]);

        // B's Job already carries an ASN → B cannot be merged into A's Job.
        app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'job_id' => $b->job_id]);
        $this->actingAs($cs)->from(route('orders.inbound.index'))
            ->post(route('orders.inbound.store'), ['order_ids' => [$a->id, $b->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel'])
            ->assertSessionHasErrors(['inbound' => __('orders.inbound.errors.job_has_asn', ['order_no' => $b->order_no, 'job_no' => $b->job->job_no])]);
        $this->assertSame(1, Asn::query()->count());
        $this->assertNull($a->lines()->first()->asn_line_id);
        $this->assertSame($b->job_id, $b->fresh()->job_id, 'nothing moved');

        // B alone works — under its own Job, next to the existing ASN; afterwards it is fully linked and refused.
        $this->actingAs($cs)->post(route('orders.inbound.store'), ['order_ids' => [$b->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel'])->assertRedirect();
        $this->assertSame(2, Asn::query()->where('job_id', $b->job_id)->count());
        $this->assertNotNull($b->lines()->first()->asn_line_id);
        $this->actingAs($cs)->from(route('orders.inbound.index'))
            ->post(route('orders.inbound.store'), ['order_ids' => [$b->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel'])
            ->assertSessionHasErrors(['inbound' => __('orders.inbound.errors.already_linked', ['order_no' => $b->order_no])]);

        // A cancelled order is not eligible; the worklist empties.
        app(OrderStatusService::class)->transitionOperational($a, 'cancelled', $cs->id);
        $this->actingAs($cs)->from(route('orders.inbound.index'))
            ->post(route('orders.inbound.store'), ['order_ids' => [$a->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel'])
            ->assertSessionHasErrors(['inbound' => __('orders.inbound.errors.order_not_eligible', ['order_no' => $a->order_no])]);
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()->assertSee(__('orders.inbound.empty'));
        $this->assertSame(0, app(OrderInboundService::class)->pendingCount());

        // A container booking needs its container number (Chinese validation).
        $c = $this->order($client->id, 'MK-C', [['en' => 'Bowls', 'qty' => 2]]);
        $this->actingAs($cs)->from(route('orders.inbound.index'))
            ->post(route('orders.inbound.store'), ['order_ids' => [$c->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container'])
            ->assertSessionHasErrors(['container_no', 'container_size']);
    }
}
