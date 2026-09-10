<?php

namespace Tests\Feature\Platform;

use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Consumers\OrderConfirmedConsumer;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\FailingConsumer;
use Tests\Support\Outbox\RecordingConsumer;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** Screen-facing events are delivered right after commit (config erp.outbox_dispatch_now); cron stays the safety net. */
class OutboxDispatchNowTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_a_listed_event_is_consumed_as_soon_as_the_business_transaction_commits(): void
    {
        config(['erp.outbox_dispatch_now' => ['test.event']]);
        app(ConsumerRegistry::class)->register('test.event', RecordingConsumer::class);
        $event = new TestEvent(['n' => 1], 'test.event');

        DB::transaction(function () use ($event) {
            app(OutboxPublisher::class)->publish($event);
            $this->assertDatabaseMissing('consumed_events', ['event_id' => $event->eventId]); // nothing before the commit
        });

        $this->assertDatabaseHas('consumed_events', ['event_id' => $event->eventId, 'consumer' => RecordingConsumer::class]);
        $this->assertSame('published', OutboxEvent::query()->where('event_id', $event->eventId)->value('status'));

        // An unlisted event still waits for cron.
        $later = new TestEvent(['n' => 2], 'other.event');
        app(ConsumerRegistry::class)->register('other.event', RecordingConsumer::class);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish($later));
        $this->assertSame('pending', OutboxEvent::query()->where('event_id', $later->eventId)->value('status'));
    }

    public function test_confirming_an_order_builds_the_fulfilment_in_the_same_request_without_waiting_for_cron(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'NOW1', 'cartons' => 10]]);
        $order = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $asn->job_id, 'order_type' => 'from_stock', 'external_ref' => 'NOW-1',
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 4, 'asn_line_id' => $asnLines[0]->id]],
        ], null, 'manual');

        app(OrderStatusService::class)->transitionOperational($order, 'confirmed'); // no dispatchDue() here on purpose

        $fulfilment = DB::table('fulfilments')->where('order_id', $order->id)->first();
        $this->assertNotNull($fulfilment, 'order.confirmed → stock.reserved → fulfilment must complete inside the confirming request');
        $this->assertSame('allocated', $fulfilment->status);
        $this->assertSame(0, OutboxEvent::query()->where('job_id', $asn->job_id)->where('status', 'pending')->count()); // the whole chain was delivered
        $this->assertSame(1, DB::table('consumed_events')->where('consumer', OrderConfirmedConsumer::class)->count()); // reserved exactly once
    }

    public function test_a_failing_consumer_does_not_break_the_publishing_request_and_is_left_for_cron(): void
    {
        config(['erp.outbox_dispatch_now' => ['test.event']]);
        app(ConsumerRegistry::class)->register('test.event', FailingConsumer::class);
        $event = new TestEvent(['n' => 3], 'test.event');

        DB::transaction(fn () => app(OutboxPublisher::class)->publish($event)); // must not throw

        $row = OutboxEvent::query()->where('event_id', $event->eventId)->firstOrFail();
        $this->assertSame(['failed', 1], [$row->status, (int) $row->attempts]);
        $this->assertTrue($row->available_at->isFuture()); // backed off; dispatchDue() will retry
    }
}
