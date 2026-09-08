<?php

namespace Tests\Feature\Portal;

use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderHoldService;
use App\Modules\Orders\Services\OrderStatusService;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TrackingEvent;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A9-p / OMS-10 / PLT-3 (ERP_PLAN §3.8 #5): a client sees only its own orders, can order, track, download its POD and raise a return. */
class PortalOrdersTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_client_sees_only_its_own_orders_and_another_clients_order_is_a_404(): void
    {
        $client = $this->client(['name' => 'Portal Client']);
        $other = $this->client(['name' => 'Other Client']);
        $mine = $this->order($client, ['external_ref' => 'PO-MINE-1', 'consignment_mark' => 'MINE']);
        $mineDelivered = $this->order($client, ['external_ref' => 'PO-MINE-2']);
        $theirs = $this->order($other, ['external_ref' => 'PO-THEIRS']);
        foreach (['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'] as $status) {
            app(OrderStatusService::class)->transitionOperational($mineDelivered, $status);
        }
        $user = $this->clientUser($client);

        $this->actingAs($user)->get('/portal')->assertOk()
            ->assertSee($mine->order_no)->assertSee($mineDelivered->order_no)->assertDontSee($theirs->order_no)
            ->assertSee(__('orders.customer_statuses.received'))->assertSee(__('orders.customer_statuses.delivered'));
        $this->actingAs($user)->get('/portal?q=PO-MINE-1')->assertOk()->assertSee($mine->order_no)->assertDontSee($mineDelivered->order_no);
        $this->actingAs($user)->get('/portal?status=delivered')->assertOk()->assertSee($mineDelivered->order_no)->assertDontSee($mine->order_no);
        $this->actingAs($user)->get('/portal?from='.today()->addDays(5)->toDateString())->assertOk()->assertSee(__('portal.empty'));

        $this->actingAs($user)->get(route('portal.orders.show', $mine))->assertOk()->assertSee('MINE');
        $this->actingAs($user)->get(route('portal.orders.show', $theirs))->assertNotFound();
        $this->actingAs($user)->get('/orders')->assertForbidden(); // client users never leave /portal
        $this->actingAs($user)->get(route('orders.show', $mine))->assertForbidden();
    }

    public function test_client_creates_an_order_from_the_portal_with_the_address_book_and_cannot_pick_another_client(): void
    {
        $client = $this->client();
        $other = $this->client();
        $user = $this->clientUser($client);
        $address = ClientAddress::query()->create(['client_id' => $client->id, 'label' => 'FBA BWU2', 'contact_name' => 'Amazon BWU2', 'address' => '1 Distribution Drive', 'suburb' => 'Kemps Creek', 'state' => 'NSW', 'postcode' => '2178', 'address_type' => 'fba', 'default_instructions' => 'Book a slot first']);

        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()->assertSee('FBA BWU2');
        $this->actingAs($user)->post(route('portal.orders.store'), [
            'client_id' => $other->id, // ignored: the signed-in client owns the order
            'order_type' => 'from_stock', 'external_ref' => 'PORTAL-PO-1', 'client_address_id' => $address->id,
            'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 5, 'actual_weight_kg' => 12]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $order = Order::query()->withoutGlobalScopes()->where('external_ref', 'PORTAL-PO-1')->sole();
        $this->assertSame([$client->id, 'portal', 'received', $user->id], [$order->client_id, $order->source, $order->operational_status, $order->created_by]);
        $this->assertSame(['Amazon BWU2', 'Kemps Creek', 'fba', 'Book a slot first'], [$order->deliver_to_name, $order->deliver_to_suburb, $order->deliver_to_address_type, $order->delivery_instructions]);
        $this->assertNotNull($order->job_id);
        $this->assertSame($client->id, $order->job->client_id);
        $this->assertSame(1, $address->fresh()->usage_count);
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee('展示架')->assertSee(__('orders.customer_statuses.received'));

        // Staff cannot place portal orders (they use /orders), and a client user without a client is refused by the scope.
        $this->actingAs($this->staff('customer_service'))->get(route('portal.orders.create'))->assertForbidden();
    }

    public function test_client_gets_an_estimate_before_confirming_and_the_preview_creates_no_order(): void
    {
        // Tester feedback #10: "获取估价" prices the form without saving anything; "确认提交订单" then creates the order.
        $client = $this->client();
        $user = $this->clientUser($client);
        $payload = [
            'order_type' => 'from_stock', 'external_ref' => 'PORTAL-PREVIEW-1',
            'deliver_to_name' => 'Amazon BWU2', 'deliver_to_phone' => '0400000000', 'deliver_to_address' => '1 Distribution Drive', 'deliver_to_suburb' => 'Kemps Creek',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2178', 'deliver_to_address_type' => 'fba',
            'requested_date' => today()->addDays(3)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '展示架', 'package_type' => 'carton', 'carton_qty' => 5, 'actual_weight_kg' => 12]],
        ];

        $this->actingAs($user)->get(route('portal.orders.create'))->assertOk()
            ->assertSee(__('portal.actions.get_estimate'))->assertDontSee(__('portal.estimate.preview_title'));

        $this->actingAs($user)->post(route('portal.orders.preview'), $payload)->assertOk()
            ->assertSee(__('portal.estimate.preview_title'))->assertSee('WH-ORDER-DESPATCH')->assertSee(__('portal.actions.confirm_submit'))
            ->assertSee('PORTAL-PREVIEW-1'); // the typed values survive the round trip
        $this->assertSame(0, Order::query()->withoutGlobalScopes()->count());

        $this->actingAs($user)->post(route('portal.orders.preview'), ['order_type' => 'from_stock'])->assertSessionHasErrors(['deliver_to_name', 'requested_date', 'lines']);

        $this->actingAs($user)->post(route('portal.orders.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Order::query()->withoutGlobalScopes()->where('external_ref', 'PORTAL-PREVIEW-1')->count());
    }

    public function test_client_downloads_only_its_own_pod_and_never_sees_internal_notes(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $other = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client);
        $otherOrder = $this->order($other);
        $finance = $this->staff('finance');
        app(OrderHoldService::class)->place($order, 'financial', 'INTERNAL-NOTE credit limit exceeded', $finance->id);

        [$pod, $podDocumentId] = $this->deliveredShipment($order, 'SHP-MINE');
        [, $otherDocumentId] = $this->deliveredShipment($otherOrder, 'SHP-THEIRS');
        $hiddenDocumentId = app(DocumentService::class)->attach('docket', 'order', $order->id, 'orders/internal-docket.pdf', ['client_id' => $client->id, 'client_visible' => false, 'original_name' => 'docket.pdf']);
        Storage::disk('local')->put('orders/internal-docket.pdf', '%PDF-internal');

        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()
            ->assertSee('SHP-MINE')->assertSee('Left depot')->assertSee(__('portal.tracking.download_pod'))->assertSee(route('portal.documents.download', $podDocumentId))
            ->assertDontSee('INTERNAL-NOTE')->assertDontSee(route('transport.orders.margin', $order->id))->assertDontSee(__('orders.holds.types.financial'));

        $this->actingAs($user)->get(route('portal.documents.download', $podDocumentId))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get(route('portal.documents.download', $otherDocumentId))->assertNotFound();
        $this->actingAs($user)->get(route('portal.documents.download', $hiddenDocumentId))->assertForbidden();
        $this->actingAs($this->staff('customer_service'))->get(route('portal.documents.download', $hiddenDocumentId))->assertOk();
    }

    public function test_client_requests_a_return_from_the_portal_on_a_delivered_order(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client);
        $this->actingAs($user)->post(route('portal.orders.returns.store', $order), ['quantities' => [$order->lines->first()->id => 1], 'reason' => 'x'])->assertSessionHasErrors('return');

        foreach (['confirmed', 'allocated', 'picking', 'packed', 'dispatched', 'delivered'] as $status) {
            app(OrderStatusService::class)->transitionOperational($order, $status);
        }
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee(__('portal.returns.request'));
        $this->actingAs($user)->post(route('portal.orders.returns.store', $order), ['quantities' => [$order->lines->first()->id => 2], 'reason' => 'wrong colour'])->assertSessionHasNoErrors()->assertRedirect();

        $return = Order::query()->withoutGlobalScopes()->where('order_type', 'return')->sole();
        $this->assertSame([$client->id, 'portal', $order->id, 'confirmed', $user->id], [$return->client_id, $return->source, $return->original_order_id, $return->operational_status, $return->created_by]);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'return.requested', 'client_id' => $client->id]);
        $this->actingAs($user)->get(route('portal.orders.show', $return))->assertOk()->assertSee($order->order_no)->assertSee(__('portal.returns.pickup_title'));
        $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk()->assertSee($return->order_no);
    }

    /** Tester feedback item 2: 订单信息 and the address blocks render as tidy label / value grids, not a stacked list. */
    public function test_order_page_renders_order_info_and_addresses_as_label_value_grids(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $order = $this->order($client, ['external_ref' => 'PO-KV-1', 'deliver_to_phone' => '0400123456', 'delivery_instructions' => 'Ring the bell', 'deliver_to_address_type' => 'residential']);

        $page = $this->actingAs($user)->get(route('portal.orders.show', $order))->assertOk();
        $page->assertSee('<dl class="kv kv-2">', false)->assertSee('<dl class="kv">', false) // dl.kv styles live in public/css/app.css (CHANGE_REQUESTS #80)
            ->assertSee('<dt>'.__('portal.fields.reference').'</dt><dd>PO-KV-1</dd>', false)
            ->assertSee('<dt>'.__('portal.fields.tailgate').'</dt><dd>'.__('portal.tailgate.required').'</dd>', false) // residential → tailgate
            ->assertSeeInOrder([
                __('portal.fields.reference'), 'PO-KV-1', __('portal.fields.consignment_mark'), __('portal.fields.fba_reference'), __('portal.fields.requested_date'), __('portal.fields.service_level'),
                __('portal.sections.delivery'), __('portal.fields.deliver_to_name'), 'Receiver', __('portal.fields.deliver_to_phone'), '0400123456', __('portal.fields.address'), '1 Test St, Melbourne VIC 3000',
                __('portal.fields.address_type'), __('orders.address_types.residential'), __('portal.fields.delivery_instructions'), 'Ring the bell',
            ]);

        // A pure transport order shows its pickup block in the same grid.
        $pickup = $this->order($client, ['order_type' => 'pickup_deliver', 'lines' => [], 'pickup_address' => ['name' => 'Factory', 'phone' => null, 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028'], 'declared_packages' => [['package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 100]]]);
        $this->actingAs($user)->get(route('portal.orders.show', $pickup))->assertOk()
            ->assertSeeInOrder([__('portal.sections.pickup'), '<dl class="kv">', __('portal.pickup.name'), 'Factory', __('portal.pickup.phone'), __('portal.not_provided'), __('portal.pickup.address'), '9 Supplier Rd, Laverton VIC 3028'], false);
    }

    private function order(Client $client, array $overrides = []): Order
    {
        $job = app(JobService::class)->create($client->id, 'loose')['job_id'];

        return app(OrderCreationService::class)->create(array_replace([
            'client_id' => $client->id, 'job_id' => $job, 'order_type' => 'from_stock', 'external_ref' => 'REF-'.uniqid(),
            'deliver_to_name' => 'Receiver', 'deliver_to_address' => '1 Test St', 'deliver_to_suburb' => 'Melbourne', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3000',
            'deliver_to_address_type' => 'business', 'requested_date' => today()->addDay()->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Goods', 'package_type' => 'carton', 'carton_qty' => 3]],
        ], $overrides), null, 'manual');
    }

    /** @return array{Pod, int} */
    private function deliveredShipment(Order $order, string $shipmentNo): array
    {
        $shipment = Shipment::query()->create(['shipment_no' => $shipmentNo, 'job_id' => $order->job_id, 'client_id' => $order->client_id, 'order_id' => $order->id, 'shipment_type' => 'outbound', 'status' => 'delivered', 'tracking_number' => 'TRK-'.$shipmentNo]);
        TrackingEvent::query()->create(['shipment_id' => $shipment->id, 'status' => 'Left depot', 'location' => 'Melbourne', 'source' => 'driver', 'occurred_at' => now()->subHour(), 'created_at' => now()]);
        $path = "transport/shipments/{$shipment->id}/pod/{$shipmentNo}-pod.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 test');
        $documentId = app(DocumentService::class)->attach('pod', 'shipment', $shipment->id, $path, ['job_id' => $order->job_id, 'client_id' => $order->client_id, 'client_visible' => true, 'original_name' => $shipmentNo.'-pod.pdf', 'mime' => 'application/pdf']);
        $pod = Pod::query()->create(['shipment_id' => $shipment->id, 'delivered_at' => now(), 'recipient_name' => 'Dock Manager', 'pod_document_id' => $documentId]);

        return [$pod, $documentId];
    }
}
