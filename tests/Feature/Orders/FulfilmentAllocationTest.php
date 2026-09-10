<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\PutawayService;
use App\Modules\Warehouse\Services\ReceivingService;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** A7: goods-line stock check, partial allocation/backorder, replenishment and split-delivery completion. */
class FulfilmentAllocationTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** ERP_PLAN §3.8 #8: partial stock creates F1, replenishment creates F2, and only the second POD closes the order. */
    public function test_partial_stock_is_split_into_two_fulfilments_and_order_closes_only_after_both_are_delivered(): void
    {
        [$user, $client, $warehouse, $line, $order] = $this->stockOrder(5, 8);

        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(__('orders.fulfilments.availability_statuses.partial'))
            ->assertSee('5');

        $this->actingAs($user)->post(route('orders.confirm', $order))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // First pass handles the putaway + order events; the second handles Warehouse's reservation reply.
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();

        $order = $order->fresh()->load('lines', 'fulfilments.lines');
        $this->assertSame('allocated', $order->operational_status);
        $this->assertSame('partial', $order->fulfilment_status);
        $this->assertSame(3, $order->lines->first()->qty_backordered);
        $this->assertSame(['F1'], $order->fulfilments->pluck('seq')->all());
        $this->assertSame(5, $order->fulfilments->first()->lines->first()->qty);

        $newUnit = $this->receiveAndPutAway($line, $warehouse, 3, 8);
        app(OutboxDispatcher::class)->dispatchDue();

        $order = $order->fresh()->load('lines', 'fulfilments.lines');
        $this->assertSame(['F1', 'F2'], $order->fulfilments->pluck('seq')->all());
        $this->assertSame([5, 3], $order->fulfilments->map(fn ($batch) => $batch->lines->sum('qty'))->all());
        $this->assertSame(0, $order->lines->first()->qty_backordered);
        $this->assertSame(3, $newUnit->fresh()->qty_reserved);

        $first = $order->fulfilments[0];
        $second = $order->fulfilments[1];
        $this->publishPod($order, $first->id, 9001);
        app(OutboxDispatcher::class)->dispatchDue();

        // Block 2: the order follows its slowest batch — F1 delivered, F2 still allocated → the order is at most picking.
        $order = $order->fresh()->load('lines', 'fulfilments');
        $this->assertSame('picking', $order->operational_status);
        $this->assertSame('partial', $order->fulfilment_status);
        $this->assertSame(5, $order->lines->first()->qty_shipped);
        $this->assertSame(['delivered', 'allocated'], $order->fulfilments->pluck('status')->all());

        $this->publishPod($order, $second->id, 9002);
        app(OutboxDispatcher::class)->dispatchDue();

        $order = $order->fresh()->load('lines', 'fulfilments', 'events');
        $this->assertSame('delivered', $order->operational_status);
        $this->assertSame('fulfilled', $order->fulfilment_status);
        $this->assertSame(8, $order->lines->first()->qty_shipped);
        $this->assertSame(['delivered', 'delivered'], $order->fulfilments->pluck('status')->all());
        $this->assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'dimension' => 'fulfilment',
            'from_status' => 'partial',
            'to_status' => 'fulfilled',
            'actor_type' => 'system',
        ]);

        $this->actingAs($user)->get(route('orders.fulfilments.index', $order))
            ->assertOk()
            ->assertSee($order->order_no.'-F1')
            ->assertSee($order->order_no.'-F2')
            ->assertSee(__('orders.statuses.fulfilment.fulfilled'))
            // 2026-09-10 i18n sweep: the availability table header is Chinese (was the English "Backorder").
            ->assertSee(__('orders.fulfilments.fields.backordered'))
            ->assertDontSee('Backorder');
    }

    public function test_reservations_from_two_warehouses_create_one_batch_per_warehouse(): void
    {
        [$user, $client, $warehouse, $firstLine, $order] = $this->stockOrder(6, 6);
        $secondWarehouse = $this->warehouse('SYD');
        $secondAsn = app(AsnService::class)->create([
            'client_id' => $client->id,
            'job_id' => $order->job_id,
            'warehouse_id' => $secondWarehouse->id,
            'inbound_type' => 'loose_truck',
        ]);
        [$secondLine] = app(AsnService::class)->addLines($secondAsn, [['description' => 'Second goods line', 'expected_cartons' => 4]]);
        $order->lines()->create(['description_en' => 'Second goods line', 'package_type' => 'carton', 'carton_qty' => 4, 'asn_line_id' => $secondLine->id]);

        $order->update(['operational_status' => 'confirmed']);
        $this->publishStockReserved($order, [
            ['reservation_id' => 1, 'order_line_id' => $order->lines()->oldest('id')->firstOrFail()->id, 'asn_line_id' => $firstLine->id, 'stock_unit_id' => 100, 'warehouse_id' => $warehouse->id, 'qty' => 6],
            ['reservation_id' => 2, 'order_line_id' => $order->lines()->latest('id')->firstOrFail()->id, 'asn_line_id' => $secondLine->id, 'stock_unit_id' => 101, 'warehouse_id' => $secondWarehouse->id, 'qty' => 4],
        ]);
        app(OutboxDispatcher::class)->dispatchDue();

        $order = $order->fresh()->load('fulfilments.lines');
        $this->assertCount(2, $order->fulfilments);
        $this->assertSame([$warehouse->id, $secondWarehouse->id], $order->fulfilments->pluck('warehouse_id')->sort()->values()->all());
        $this->assertSame(10, $order->fulfilments->flatMap->lines->sum('qty'));

        $this->actingAs($user)->get(route('orders.fulfilments.index', $order))->assertOk();
    }

    public function test_from_stock_order_cannot_be_confirmed_until_every_line_is_linked_to_an_asn_goods_line(): void
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $order = $this->createOrder($user, $client, $asn->job_id, null, 2);

        $this->actingAs($user)->post(route('orders.confirm', $order))
            ->assertSessionHasErrors('order');

        $this->assertSame('received', $order->fresh()->operational_status);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'order.confirmed', 'job_id' => $order->job_id]);
    }

    /** @return array{User, Client, Warehouse, AsnLine, Order} */
    private function stockOrder(int $stockQty, int $orderQty): array
    {
        $user = $this->staff('customer_service');
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Display stands', 'expected_cartons' => $stockQty]]);
        $this->receiveAndPutAway($line, $warehouse, $stockQty, $stockQty);
        $order = $this->createOrder($user, $client, $asn->job_id, $line->id, $orderQty);

        return [$user, $client, $warehouse, $line, $order];
    }

    private function createOrder(User $user, Client $client, int $jobId, ?int $asnLineId, int $qty): Order
    {
        return app(OrderCreationService::class)->create([
            'client_id' => $client->id,
            'job_id' => $jobId,
            'order_type' => 'from_stock',
            'consignment_mark' => 'A7-MARK',
            'deliver_to_name' => 'Amazon BWU2',
            'deliver_to_address' => '1 Distribution Drive',
            'deliver_to_suburb' => 'Kemps Creek',
            'deliver_to_state' => 'NSW',
            'deliver_to_postcode' => '2178',
            'deliver_to_address_type' => 'fba',
            'requested_date' => '2026-09-30',
            'service_level' => 'standard',
            'lines' => [[
                'description_en' => 'Display stands',
                'package_type' => 'carton',
                'carton_qty' => $qty,
                'asn_line_id' => $asnLineId,
            ]],
        ], $user->id, 'manual');
    }

    private function receiveAndPutAway(AsnLine $line, Warehouse $warehouse, int $unitQty, int $receivedTotal): StockUnit
    {
        [$unit] = app(ReceivingService::class)->receiveLine($line, [
            'received_cartons' => $receivedTotal,
            'units' => [['unit_type' => 'carton', 'carton_qty' => $unitQty]],
        ], $this->location($warehouse, 'receiving'));
        app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));

        return $unit;
    }

    /** @param list<array<string, mixed>> $reservations */
    private function publishStockReserved(Order $order, array $reservations): void
    {
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([
            'order_id' => $order->id,
            'job_id' => $order->job_id,
            'client_id' => $order->client_id,
            'fully_reserved' => true,
            'reservations' => $reservations,
        ], 'stock.reserved', $order->job_id, $order->client_id)));
    }

    private function publishPod(Order $order, int $fulfilmentId, int $shipmentId): void
    {
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([
            'shipment_id' => $shipmentId,
            'shipment_no' => 'SHP-'.$shipmentId,
            'job_id' => $order->job_id,
            'client_id' => $order->client_id,
            'order_id' => $order->id,
            'fulfilment_id' => $fulfilmentId,
            'delivered_at' => now()->toIso8601String(),
            'recipient_name' => 'Warehouse Receiver',
            'pod_document_id' => 1,
            'photo_document_ids' => [],
            'captured_by_type' => 'carrier_api',
            'captured_by' => null,
        ], 'delivery.pod_captured', $order->job_id, $order->client_id)));
    }
}
