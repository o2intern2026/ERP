<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Fulfilment;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/**
 * Block 2 task 1: Warehouse / Transport events drive the order status (enums.md §3 "only Orders writes orders.*_status").
 * task.completed (pick) → picking, outbound.packed → packed, outbound.dispatched → dispatched, delivery.pod_captured → delivered.
 */
class OutboundEventConsumersTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_real_pick_pack_dispatch_and_pod_events_walk_the_order_to_delivered(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'EV1', 'cartons' => 10, 'weight_kg' => 100]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 6]]);
        $fulfilment = $order->fulfilments()->sole();
        $this->assertSame(['allocated', 'allocated'], [$order->operational_status, $fulfilment->status]);

        $outbound = app(OutboundService::class);
        $task = $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id)['tasks']->sole();
        foreach ($task->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $operator->id);
        }
        app(OutboxDispatcher::class)->dispatchDue(); // task.completed (pick)
        $order->refresh();
        $this->assertSame('picking', $order->operational_status);
        $this->assertSame('picking', $fulfilment->fresh()->status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'from_status' => 'allocated', 'to_status' => 'picking', 'actor_type' => 'system']);

        $outbound->pack($fulfilment->id, [['package_type' => 'carton', 'weight_kg' => 12]], $operator->id);
        app(OutboxDispatcher::class)->dispatchDue(); // outbound.packed
        $this->assertSame('packed', $order->fresh()->operational_status);
        $this->assertSame('packed', $fulfilment->fresh()->status);

        $outbound->dispatch($fulfilment->id, 0, 'carrier', null, $operator->id);
        app(OutboxDispatcher::class)->dispatchDue(); // outbound.dispatched
        $order->refresh()->load('lines');
        $this->assertSame('dispatched', $order->operational_status);
        $this->assertSame('dispatched', $fulfilment->fresh()->status);
        $this->assertSame(6, $order->lines->first()->qty_shipped);
        $this->assertSame('partial', $order->fulfilment_status);

        $this->publish('delivery.pod_captured', [
            'shipment_id' => 501, 'shipment_no' => 'SHP-TEST-1', 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'order_id' => $order->id,
            'fulfilment_id' => $fulfilment->id, 'delivered_at' => now()->toIso8601String(), 'recipient_name' => 'Dock', 'pod_document_id' => null,
            'photo_document_ids' => [], 'captured_by_type' => 'driver', 'captured_by' => null,
        ], $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $order->refresh();
        $this->assertSame(['delivered', 'fulfilled'], [$order->operational_status, $order->fulfilment_status]);
        $this->assertSame(['delivered', 501], [$fulfilment->fresh()->status, $fulfilment->fresh()->shipment_id]);
        $this->assertSame(
            ['received', 'confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'],
            $order->events()->where('dimension', 'operational')->where(fn ($q) => $q->whereColumn('from_status', '!=', 'to_status')->orWhereNull('from_status'))->pluck('to_status')->all(),
        );

        // Replay tolerance: a second delivery of the same facts changes nothing and never resurrects a finished order.
        $this->publish('outbound.packed', $this->packedPayload($order, $fulfilment->id), $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('delivered', $order->fresh()->operational_status);
        $this->assertSame('delivered', $fulfilment->fresh()->status);
    }

    public function test_order_is_packed_and_dispatched_only_when_every_batch_is(): void
    {
        [$order, $first, $second] = $this->twoBatchOrder();

        $this->publish('outbound.packed', $this->packedPayload($order, $first->id), $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('picking', $order->fresh()->operational_status, 'one packed batch means the order is at least picking, not packed');
        $this->assertSame(['packed', 'allocated'], [$first->fresh()->status, $second->fresh()->status]);

        $this->publish('outbound.packed', $this->packedPayload($order, $second->id), $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('packed', $order->fresh()->operational_status);

        $this->publish('outbound.dispatched', $this->dispatchedPayload($order, $first->id, 77), $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('packed', $order->fresh()->operational_status);
        $this->assertSame(['dispatched', 77], [$first->fresh()->status, $first->fresh()->shipment_id]);

        $this->publish('outbound.dispatched', $this->dispatchedPayload($order, $second->id, null), $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $order->refresh()->load('lines');
        $this->assertSame('dispatched', $order->operational_status);
        $this->assertSame(10, (int) $order->lines->sum('qty_shipped'));
    }

    public function test_a_late_pick_event_does_not_move_a_packed_batch_backwards(): void
    {
        [$order, $first] = $this->twoBatchOrder();
        $this->publish('outbound.packed', $this->packedPayload($order, $first->id), $order);
        app(OutboxDispatcher::class)->dispatchDue();

        $this->publish('task.completed', ['task_id' => 1, 'task_no' => 'TSK-1', 'task_type' => 'pick', 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'warehouse_id' => $first->warehouse_id,
            'source_type' => 'fulfilment', 'source_id' => $first->id, 'order_id' => $order->id, 'fulfilment_id' => $first->id, 'asn_id' => null, 'container_id' => null, 'container' => null,
            'billable_qty' => null, 'billable_uom' => null, 'hours_business' => null, 'hours_after_hours' => null, 'scan_count' => null,
            'started_at' => now()->toIso8601String(), 'completed_at' => now()->toIso8601String(), 'completed_by' => null], $order);
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame('packed', $first->fresh()->status);
        $this->assertSame('picking', $order->fresh()->operational_status);
    }

    public function test_events_never_resurrect_a_cancelled_order(): void
    {
        [$order, $first] = $this->twoBatchOrder();
        app(OrderStatusService::class)->transitionOperational($order, 'cancelled', $this->staff('admin')->id, 'client withdrew');

        $this->publish('outbound.packed', $this->packedPayload($order, $first->id), $order);
        $this->publish('outbound.dispatched', $this->dispatchedPayload($order, $first->id, null), $order);
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame('cancelled', $order->fresh()->operational_status);
        $this->assertSame('allocated', $first->fresh()->status);
    }

    public function test_pod_on_a_pure_transport_order_moves_it_from_confirmed_to_delivered(): void
    {
        $client = $this->client();
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'order_type' => 'pickup_deliver', 'external_ref' => 'PT-1',
            'pickup_address' => ['name' => 'Factory', 'phone' => null, 'address' => '2 Pickup Rd', 'suburb' => 'Dandenong', 'state' => 'VIC', 'postcode' => '3175'],
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->toDateString(), 'service_level' => 'standard',
            'declared_packages' => [['package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 300]],
        ], null, 'manual');
        app(OrderStatusService::class)->transitionOperational($order, 'confirmed');

        $this->publish('delivery.pod_captured', [
            'shipment_id' => 9, 'shipment_no' => 'SHP-PT', 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'order_id' => $order->id, 'fulfilment_id' => null,
            'delivered_at' => now()->toIso8601String(), 'recipient_name' => 'R', 'pod_document_id' => null, 'photo_document_ids' => [], 'captured_by_type' => 'driver', 'captured_by' => null,
        ], $order);
        app(OutboxDispatcher::class)->dispatchDue();

        $order->refresh();
        $this->assertSame('delivered', $order->operational_status);
        $this->assertSame(['received', 'confirmed', 'dispatched', 'delivered'], $order->events()->where('dimension', 'operational')->pluck('to_status')->unique()->values()->all());
    }

    /** @return array{Order, Fulfilment, Fulfilment} */
    private function twoBatchOrder(): array
    {
        $client = $this->client();
        $melbourne = $this->warehouse();
        $sydney = $this->warehouse('SYD');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $melbourne, [['mark' => 'TWO', 'cartons' => 10]]);
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 10, 'asn_line_id' => $asnLines[0]->id]],
        ], null, 'manual');
        $order->update(['operational_status' => 'confirmed']);
        $lineId = $order->lines()->sole()->id;
        $this->publish('stock.reserved', ['order_id' => $order->id, 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'fully_reserved' => true, 'reservations' => [
            ['reservation_id' => 1, 'order_line_id' => $lineId, 'asn_line_id' => $asnLines[0]->id, 'stock_unit_id' => 1, 'warehouse_id' => $melbourne->id, 'qty' => 6],
            ['reservation_id' => 2, 'order_line_id' => $lineId, 'asn_line_id' => $asnLines[0]->id, 'stock_unit_id' => 2, 'warehouse_id' => $sydney->id, 'qty' => 4],
        ]], $order);
        app(OutboxDispatcher::class)->dispatchDue();
        $order->refresh();
        $this->assertSame('allocated', $order->operational_status);
        [$first, $second] = $order->fulfilments()->orderBy('id')->get();

        return [$order, $first, $second];
    }

    /** @return array<string, mixed> */
    private function packedPayload(Order $order, int $fulfilmentId): array
    {
        return ['order_id' => $order->id, 'order_no' => $order->order_no, 'fulfilment_id' => $fulfilmentId, 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'warehouse_id' => 1,
            'is_urgent' => false, 'lines' => [], 'packages' => [], 'pallet_count' => 0, 'carton_count' => 0, 'label_count' => 0, 'packed_by' => null, 'packed_at' => now()->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function dispatchedPayload(Order $order, int $fulfilmentId, ?int $shipmentId): array
    {
        return ['order_id' => $order->id, 'fulfilment_id' => $fulfilmentId, 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'warehouse_id' => 1,
            'shipment_id' => $shipmentId, 'handed_to' => 'carrier', 'pallet_count' => 0, 'package_count' => 1, 'carton_labels' => [], 'dispatched_by' => null, 'dispatched_at' => now()->toIso8601String()];
    }

    /** @param array<string, mixed> $payload */
    private function publish(string $event, array $payload, Order $order): void
    {
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent($payload, $event, $order->job_id, $order->client_id)));
    }
}
