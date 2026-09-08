<?php

namespace Tests\Feature\Orders;

use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Warehouse\Services\OutboundService;
use App\Support\Contracts\JobService;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\Outbox\TestEvent;
use Tests\TestCase;

/** ERP_PLAN §3.8 #3 billing line: Billing's `invoice.issued` (contracts/events.md, CHANGE_REQUESTS #65) moves the invoiced orders from unbilled to billed. */
class InvoiceIssuedConsumerTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_invoice_issued_moves_the_listed_orders_to_billed_once_and_leaves_the_rest(): void
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];
        $unbilled = $this->order($client, $job);
        $partial = app(OrderStatusService::class)->transitionBilling($this->order($client, $job), 'partially_billed');
        $alreadyBilled = app(OrderStatusService::class)->transitionBilling($this->order($client, $job), 'billed');
        $sameJobNotListed = $this->order($client, $job); // Billing could not attribute a line to it → stays unbilled
        $payload = [
            'invoice_id' => 77, 'invoice_no' => 'INV-202609-0007', 'invoice_type' => 'service', 'client_id' => $client->id,
            'job_ids' => [$job], 'order_ids' => [$unbilled->id, $partial->id, $alreadyBilled->id],
            'subtotal_cents' => 12000, 'gst_cents' => 1200, 'total_cents' => 13200, 'issued_at' => now()->toIso8601String(), 'due_date' => today()->addDays(30)->toDateString(),
        ];

        $publish = fn () => DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent($payload, 'invoice.issued', $job, $client->id)));
        $publish();
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(['billed', 'billed', 'billed', 'unbilled'], [$unbilled->fresh()->billing_status, $partial->fresh()->billing_status, $alreadyBilled->fresh()->billing_status, $sameJobNotListed->fresh()->billing_status]);
        $this->assertSame('invoiced', $unbilled->fresh()->customerStatus());
        $note = __('orders.billing.timeline.invoiced', ['invoice_no' => 'INV-202609-0007', 'type' => __('orders.billing.invoice_types.service')]);
        $this->assertDatabaseHas('order_events', ['order_id' => $unbilled->id, 'dimension' => 'billing', 'from_status' => 'unbilled', 'to_status' => 'billed', 'actor_type' => 'system', 'note' => $note]);
        $this->assertDatabaseHas('order_events', ['order_id' => $partial->id, 'dimension' => 'billing', 'from_status' => 'partially_billed', 'to_status' => 'billed', 'note' => $note]);
        $this->assertSame(1, $alreadyBilled->events()->where('dimension', 'billing')->count(), 'an order that was already billed is skipped — no second timeline entry');
        $this->assertSame('published', OutboxEvent::query()->where('event_name', 'invoice.issued')->sole()->status);

        // Replay: the same facts again change nothing.
        $publish();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(1, $unbilled->events()->where('dimension', 'billing')->count());
        $this->assertSame(0, $sameJobNotListed->events()->where('dimension', 'billing')->count());

        // A payload naming another client's order is a contract fault: the event fails (retry → dead) instead of moving a foreign order.
        $otherClient = $this->client();
        $foreign = $this->order($otherClient, app(JobService::class)->create($otherClient->id, 'loose')['job_id']);
        DB::transaction(fn () => app(OutboxPublisher::class)->publish(new TestEvent(['order_ids' => [$foreign->id], 'invoice_no' => 'INV-BAD'] + $payload, 'invoice.issued', $job, $client->id)));
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('unbilled', $foreign->fresh()->billing_status);
        $this->assertSame('failed', OutboxEvent::query()->where('event_name', 'invoice.issued')->latest('id')->first()->status); // retried with backoff, dead-lettered after MAX_ATTEMPTS
    }

    public function test_a_real_service_invoice_issued_by_billing_marks_the_packed_order_billed(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $operator = $this->staff('warehouse_operator');
        ['asn' => $asn, 'lines' => $asnLines] = $this->stockedAsn($client, $warehouse, [['mark' => 'INV1', 'cartons' => 10, 'weight_kg' => 100]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $asnLines[0]->id, 'qty' => 6]]);
        $fulfilment = $order->fulfilments()->sole();

        // Pick + pack through Warehouse → outbound.packed → Billing charges with source order (order processing, picks, labels).
        $outbound = app(OutboundService::class);
        $task = $outbound->releaseWave($warehouse->id, ['order_ids' => [$order->id]], $operator->id)['tasks']->sole();
        foreach ($task->lines as $line) {
            $outbound->confirmPick($line, $line->required_qty, $operator->id);
        }
        $outbound->pack($fulfilment->id, [['package_type' => 'carton', 'weight_kg' => 12]], $operator->id);
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(['packed', 'unbilled'], [$order->fresh()->operational_status, $order->fresh()->billing_status]);
        $this->assertDatabaseHas('charges', ['source_type' => 'order', 'source_id' => $order->id, 'client_id' => $client->id]);

        // Finance issues the Job's service invoice → invoice.issued → Orders' consumer.
        $this->actingAs($this->staff('finance'));
        $invoices = app(InvoiceService::class);
        $invoice = $invoices->issue($invoices->draftForJob($asn->job_id));
        $this->assertContains($order->id, OutboxEvent::query()->where('event_name', 'invoice.issued')->sole()->payload['order_ids']);
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame('billed', $order->fresh()->billing_status);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'dimension' => 'billing', 'to_status' => 'billed', 'note' => __('orders.billing.timeline.invoiced', ['invoice_no' => $invoice->invoice_no, 'type' => __('orders.billing.invoice_types.service')])]);
        $this->actingAs($this->staff('customer_service'))->get(route('orders.show', $order))->assertOk()->assertSee($invoice->invoice_no)->assertSee(__('orders.statuses.billing.billed'));
    }

    private function order(Client $client, int $job): Order
    {
        return app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'INV-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3, 'actual_weight_kg' => 30]],
        ], null, 'manual');
    }
}
