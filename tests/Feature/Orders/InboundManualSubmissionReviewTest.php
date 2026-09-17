<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Asn;
use App\Support\Contracts\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\PortalCollectionFlow;
use Tests\TestCase;

/**
 * 门户手工建立入库清单 (CHANGE_REQUESTS #128), customer-service side: a manual list that created orders AND attached existing ones is ONE
 * 待建预报 card (来源 手工录入) whose 选中并填入 ticks all of them; generating the ASN from exactly those orders takes every line and the
 * client's plan; a partial generation keeps the request but not the whole-list plan (existing rule, now over created + attached);
 * awaitingAsn flags the attached order's collection request.
 */
class InboundManualSubmissionReviewTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, PortalCollectionFlow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->bindStubCarriers(['own_fleet' => ['code' => 'OWN-IMS', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => 7500]]);
    }

    /** An existing from_stock order of the client (received, one goods line with weight + dims, no ASN line). */
    private function existingOrder(Client $client, User $actor): Order
    {
        return app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'order_type' => 'from_stock', 'consignment_mark' => 'MK-O', 'deliver_to_name' => 'Shop One', 'deliver_to_phone' => '0399990001',
            'deliver_to_address' => '12 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(14)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_cn' => '电热水壶', 'description_en' => 'Kettle', 'package_type' => 'carton', 'carton_qty' => 5, 'actual_weight_kg' => 32.5, 'length_mm' => 450, 'width_mm' => 350, 'height_mm' => 300]],
        ], $actor->id, 'portal')->fresh(['lines']);
    }

    /** A confirmed manual list: one typed row (MK-A) + the attached order, 需要我们上门提货 with the own-fleet plan ticked. */
    private function confirmedManualList(User $user, Order $attached, bool $collect = true): OrderImport
    {
        $warehouse = $this->warehouse();
        $row = [
            'consignment_mark' => 'MK-A', 'description_cn' => '蓝牙音箱', 'description_en' => 'Bluetooth speaker', 'package_type' => 'carton', 'carton_qty' => '10',
            'actual_weight_kg' => '85', 'length_mm' => '600', 'width_mm' => '400', 'height_mm' => '400',
            'deliver_to_name' => 'Amazon FBA BWU2', 'deliver_to_phone' => '0400 000 001', 'deliver_to_address' => '1 Warehouse Rd', 'deliver_to_suburb' => 'Moorebank',
            'deliver_to_state' => 'NSW', 'deliver_to_postcode' => '2170', 'fba_reference' => 'FBA15ABC123', 'storage_tier' => 'standard', 'requested_date' => '',
        ];
        $this->actingAs($user)->post(route('portal.asns.imports.manual.store'), [
            'action' => 'preview', 'rows' => [$row], 'attached_order_ids' => [$attached->id], 'container_no' => 'msku0000128', 'container_size' => '40',
            'expected_date' => today()->addDays(10)->toDateString(), 'reference' => 'PO-128', 'notes' => '周五到港',
        ] + ($collect ? $this->collectionFields($warehouse) : ['inbound_transport' => 'client_delivers']))->assertSessionHasNoErrors()->assertRedirect();
        $import = OrderImport::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->confirmCollection($user, $import, $collect ? $this->planKey('own_fleet', 'standard') : null)->assertSessionHasNoErrors();
        $import->refresh();
        $this->assertSame('imported', $import->status);

        return $import;
    }

    public function test_the_manual_list_is_one_card_with_created_and_attached_orders_and_generating_from_all_of_them_carries_the_plan(): void
    {
        $client = $this->client(['name' => 'Manual Co']);
        $user = $this->clientUser($client);
        $attached = $this->existingOrder($client, $user);
        $import = $this->confirmedManualList($user, $attached);
        $created = Order::query()->withoutGlobalScopes()->where('consignment_mark', 'MK-A')->sole();
        $cs = $this->staff('customer_service');
        $ready = today()->addDays(2)->toDateString();

        $this->assertEqualsCanonicalizing([$created->id, $attached->id], $import->orderIds());

        // 待建预报: ONE card for the submission — 来源 手工录入, both orders named, the attached one called out, 选中并填入 ticking both ids.
        $page = $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()
            ->assertSee(__('orders.imports.inbound.manual'))->assertSee('MSKU0000128')->assertSee('40ft')->assertSee('PO-128')->assertSee('周五到港')
            ->assertSee($created->order_no)->assertSee($attached->order_no)->assertSee(__('orders.imports.inbound.attached', ['orders' => $attached->order_no]))
            ->assertSee(__('orders.imports.inbound.collection_badge'))->assertSee('Laverton VIC 3028')->assertSee('Edward Own Fleet')->assertSee('$75.00')
            ->assertSee('data-orders="'.$created->id.','.$attached->id.'"', false)->assertSee('data-import-id="'.$import->id.'"', false)->assertSee('data-collection="1"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-import-id="'.$import->id.'"'), 'one card for the whole submission');
        $this->assertSame(2, substr_count($page->getContent(), e(__('orders.imports.inbound.import', ['id' => $import->id]))), 'both order rows name the submission');
        $this->assertDoesNotMatchRegularExpression('/orders\.imports\.|orders\.inbound\./', $page->getContent(), 'no raw lang key');
        $this->assertDontSeeCsv($page->getContent());
        // The staff import page shows the typed rows and the attached order read-only.
        $this->actingAs($cs)->get(route('orders.imports.show', $import))->assertOk()
            ->assertSee(__('orders.imports.manual_entry'))->assertSee(__('orders.imports.manual.title'))->assertSee('蓝牙音箱')->assertSee(__('orders.imports.manual.attached_title'))
            ->assertSee($attached->order_no)->assertSee('Shop One')->assertSee('电热水壶 / Kettle')->assertSee(__('orders.imports.manual.attached_note', ['count' => 1]));
        $this->actingAs($cs)->get(route('orders.imports.index'))->assertOk()->assertSee(__('orders.imports.manual_entry'));

        // awaitingAsn: the attached order carries the client's collection request too.
        $rows = collect(app(OrderService::class)->awaitingAsn($client->id))->keyBy('order_id');
        $this->assertSame([true, true], [$rows[$created->id]['collection_requested'], $rows[$attached->id]['collection_requested']]);

        // Generate from exactly the submission's orders: one ASN with every line (typed + the attached order's), the client's plan and origin.
        $this->generateFromImport($cs, $import, ['order_ids' => [$created->id, $attached->id]])->assertSessionHasNoErrors()->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['we_collect', 'client', $import->id, $ready, 2, 'own_fleet', 7500], [
            $asn->inbound_transport, $asn->collection_requested_via, $asn->collection_import_id, $asn->collection_ready_date->toDateString(), $asn->lines()->count(), $asn->collection_preference['source'], $asn->collection_preference['customer_price_cents'],
        ]);
        $this->assertEqualsCanonicalizing([$created->lines->first()->id, $attached->lines->first()->id], $asn->lines()->pluck('order_line_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['Shop One', '电热水壶 / Kettle', 5], [$asn->lines()->where('order_line_id', $attached->lines->first()->id)->value('deliver_to_name'), $asn->lines()->where('order_line_id', $attached->lines->first()->id)->value('description'), (int) $asn->lines()->where('order_line_id', $attached->lines->first()->id)->value('expected_cartons')], '以订单为准: the ASN line of the attached order carries the ORDER\'s consignee and goods');
        $this->assertSame([$created->fresh()->job_id, $attached->fresh()->job_id], [$asn->job_id, $asn->job_id], 'both orders share the ASN\'s Job');
        $event = OutboxEvent::query()->where('event_name', 'asn.collection_requested')->sole();
        $this->assertSame(['client', 'own_fleet', 2], [$event->payload['requested_via'], $event->payload['client_preference']['source'], count($event->payload['lines'])]);
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()->assertDontSee('data-import-id="'.$import->id.'"', false);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee($asn->asn_no);
    }

    public function test_generating_only_part_of_the_manual_list_keeps_the_request_but_not_the_whole_list_plan(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $attached = $this->existingOrder($client, $user);
        $import = $this->confirmedManualList($user, $attached);
        $created = Order::query()->withoutGlobalScopes()->where('consignment_mark', 'MK-A')->sole();
        $cs = $this->staff('customer_service');

        // Only the typed order: the client's plan was priced for the whole list (attached order included) — not carried.
        $this->generateFromImport($cs, $import, ['order_ids' => [$created->id]])->assertSessionHasNoErrors();
        $first = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['we_collect', 'client', $import->id, null, 1], [$first->inbound_transport, $first->collection_requested_via, $first->collection_import_id, $first->collection_preference, $first->lines()->count()]);

        // The attached order later, with the same import: still the client's request, still no plan; a foreign order next to it is refused.
        $plain = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'order_type' => 'from_stock', 'consignment_mark' => 'MK-P', 'deliver_to_name' => 'Shop P', 'deliver_to_phone' => '0400 000 000',
            'deliver_to_address' => '1 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(14)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Kettle', 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 20, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
        ], $cs->id, 'manual');
        $this->generateFromImport($cs, $import, ['order_ids' => [$attached->id, $plain->id]])
            ->assertSessionHasErrors(['inbound' => __('orders.inbound.errors.collection_import_orders', ['id' => $import->id])]);
        $this->assertSame(1, Asn::query()->withoutGlobalScopes()->count());
        $this->generateFromImport($cs, $import, ['order_ids' => [$attached->id]])->assertSessionHasNoErrors();
        $second = Asn::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['client', $import->id, null, 'Shop One'], [$second->collection_requested_via, $second->collection_import_id, $second->collection_preference, $second->lines()->value('deliver_to_name')]);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->whereNotNull('collection_preference')->count());
    }

    public function test_a_manual_list_without_a_collection_request_is_a_plain_card_and_the_attached_order_is_not_flagged(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $attached = $this->existingOrder($client, $user);
        $import = $this->confirmedManualList($user, $attached, false);
        $created = Order::query()->withoutGlobalScopes()->where('consignment_mark', 'MK-A')->sole();
        $cs = $this->staff('customer_service');

        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()
            ->assertSee(__('orders.imports.inbound.manual'))->assertSee(__('orders.imports.inbound.client_delivers'))->assertDontSee('data-collection="1"', false)
            ->assertSee('data-orders="'.$created->id.','.$attached->id.'"', false);
        $rows = collect(app(OrderService::class)->awaitingAsn($client->id))->keyBy('order_id');
        $this->assertSame([false, false], [$rows[$created->id]['collection_requested'], $rows[$attached->id]['collection_requested']]);

        // 客户自送 with the submission's orders: one ASN, both lines, no collection.
        $this->actingAs($cs)->from(route('orders.inbound.index'))->post(route('orders.inbound.store'), [
            'order_ids' => [$created->id, $attached->id], 'warehouse_id' => $this->warehouse()->id, 'inbound_type' => 'container', 'container_no' => 'MSKU0000128', 'container_size' => '40',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['client_delivers', 2], [$asn->inbound_transport, $asn->lines()->count()]);
    }

    private function assertDontSeeCsv(string $html): void
    {
        $this->assertStringNotContainsString('.csv', $html, 'a manual list names no file');
    }
}
