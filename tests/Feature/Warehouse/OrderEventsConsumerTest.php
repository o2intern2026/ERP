<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
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

/** B4a end to end through the outbox: order.confirmed → stock.reserved / stock.reservation_failed; order.cancelled → stock.released. */
class OrderEventsConsumerTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function putAwayStock(int $clientId, int $cartons): array
    {
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $clientId, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$line] = app(AsnService::class)->addLines($asn, [['description' => 'Boxes', 'expected_cartons' => $cartons]]);
        [$unit] = app(ReceivingService::class)->receiveLine($line, ['received_cartons' => $cartons, 'units' => [['unit_type' => 'carton', 'carton_qty' => $cartons]]], $this->location($warehouse, 'receiving'));
        app(PutawayService::class)->putaway($unit, $this->location($warehouse, 'storage'));

        return [$asn, $line, $unit];
    }

    private function publishOrderConfirmed(int $clientId, int $jobId, int $orderId, int $asnLineId, int $qty, string $orderType = 'from_stock'): void
    {
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent([
            'order_id' => $orderId, 'order_no' => 'ORD-TEST-'.$orderId, 'order_type' => $orderType, 'client_id' => $clientId, 'job_id' => $jobId,
            'lines' => [['order_line_id' => 1, 'asn_line_id' => $asnLineId, 'carton_qty' => $qty]],
        ], 'order.confirmed', $jobId, $clientId)));
    }

    public function test_order_confirmed_reserves_stock_and_replies_stock_reserved(): void
    {
        $client = $this->client();
        [$asn, $line, $unit] = $this->putAwayStock($client->id, 20);
        $this->publishOrderConfirmed($client->id, $asn->job_id, 5001, $line->id, 12);

        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(12, $unit->fresh()->qty_reserved);
        $reply = OutboxEvent::query()->where('event_name', 'stock.reserved')->firstOrFail();
        $this->assertSame(5001, $reply->payload['order_id']);
        $this->assertTrue($reply->payload['fully_reserved']);
        $this->assertSame($unit->id, $reply->payload['reservations'][0]['stock_unit_id']);
        $this->assertSame(12, $reply->payload['reservations'][0]['qty']);
        $this->assertSame($asn->job_id, $reply->job_id);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'order.confirmed', 'status' => 'published']);
    }

    public function test_short_stock_replies_stock_reservation_failed_with_shortfalls(): void
    {
        $client = $this->client();
        [$asn, $line] = $this->putAwayStock($client->id, 5);
        $this->publishOrderConfirmed($client->id, $asn->job_id, 5002, $line->id, 8);

        app(OutboxDispatcher::class)->dispatchDue();

        $reply = OutboxEvent::query()->where('event_name', 'stock.reservation_failed')->firstOrFail();
        $this->assertEquals([['order_line_id' => 1, 'asn_line_id' => $line->id, 'requested_qty' => 8, 'reserved_qty' => 5, 'shortfall_qty' => 3]], array_map(fn ($s) => array_diff_key($s, ['reservation_ids' => 1]), $reply->payload['shortfalls']));
    }

    public function test_pure_transport_orders_do_not_touch_stock(): void
    {
        $client = $this->client();
        [$asn, $line, $unit] = $this->putAwayStock($client->id, 5);
        $this->publishOrderConfirmed($client->id, $asn->job_id, 5003, $line->id, 5, 'pickup_deliver');

        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(0, $unit->fresh()->qty_reserved);
        $this->assertDatabaseMissing('outbox_events', ['event_name' => 'stock.reserved']);
    }

    public function test_order_cancelled_releases_and_replies_stock_released(): void
    {
        $client = $this->client();
        [$asn, $line, $unit] = $this->putAwayStock($client->id, 20);
        $this->publishOrderConfirmed($client->id, $asn->job_id, 5004, $line->id, 7);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(7, $unit->fresh()->qty_reserved);

        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent(['order_id' => 5004, 'order_no' => 'ORD-TEST-5004', 'job_id' => $asn->job_id, 'client_id' => $client->id, 'previous_status' => 'allocated', 'cancelled_by' => 1, 'reason' => 'customer', 'cancelled_at' => now()->toIso8601String()], 'order.cancelled', $asn->job_id, $client->id)));
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(0, $unit->fresh()->qty_reserved);
        $released = OutboxEvent::query()->where('event_name', 'stock.released')->firstOrFail();
        $this->assertSame(7, $released->payload['qty']);
        $this->assertSame('order_cancelled', $released->payload['reason']);
        $this->assertDatabaseHas('stock_ledger', ['stock_unit_id' => $unit->id, 'movement_type' => 'release', 'qty' => -7]);
        $this->artisan('stock:reconcile')->assertSuccessful();
    }
}
