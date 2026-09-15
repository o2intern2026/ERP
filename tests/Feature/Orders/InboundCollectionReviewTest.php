<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\OrderLine;
use App\Modules\Orders\Services\OrderCreationService;
use App\Modules\Orders\Services\OrderInboundService;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\PortalCollectionFlow;
use Tests\TestCase;

/**
 * 客户门户申请上门提货 (CHANGE_REQUESTS #125), customer service review: 待建预报 flags the client's request and 选中并填入 carries every
 * pickup field; generating the ASN requests the collection in the same transaction with the plan read server side from the portal
 * import; a refusal (ready date passed, another client's import) leaves no ASN; staff can still request one without an import;
 * orders imported onto an existing ASN warn that the client asked for a collection.
 */
class InboundCollectionReviewTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, PortalCollectionFlow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindStubCarriers(['own_fleet' => ['code' => 'OWN-ICR', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => 7500]]);
    }

    public function test_customer_service_sees_the_request_and_generates_the_asn_with_the_clients_pickup_and_plan(): void
    {
        Storage::fake('local');
        $user = $this->clientUser($this->client(['name' => 'Collect Co']));
        $warehouse = $this->warehouse();
        $import = $this->uploadCollection($user, $warehouse);
        $this->confirmCollection($user, $import, $this->planKey('own_fleet', 'standard'))->assertSessionHasNoErrors();
        $cs = $this->staff('customer_service');
        $ready = today()->addDays(2)->toDateString();

        $page = $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()
            ->assertSee(__('orders.imports.inbound.collection_badge'))->assertSee('Laverton VIC 3028')->assertSee(__('orders.imports.inbound.ready_date', ['date' => $ready]))
            ->assertSee('Edward Own Fleet')->assertSee('$75.00')
            ->assertSee('name="inbound_transport"', false)->assertSee('id="collection-fields" hidden disabled', false)->assertSee('name="collection_import_id"', false);
        foreach (['data-import-id="'.$import->id.'"', 'data-collection="1"', 'data-warehouse-id="'.$warehouse->id.'"', 'data-collection-name="Factory"', 'data-collection-phone="0400 000 009"',
            'data-collection-address="9 Supplier Rd"', 'data-collection-suburb="Laverton"', 'data-collection-state="VIC"', 'data-collection-postcode="3028"', 'data-collection-type="business"',
            'data-collection-ready-date="'.$ready.'"', 'data-collection-notes="Dock 3, 8-4"'] as $attribute) {
            $page->assertSee($attribute, false);
        }
        $this->assertDoesNotMatchRegularExpression('/orders\.inbound\.collection\.|orders\.imports\.inbound\./', $page->getContent(), 'no raw lang key');

        // Generate with 我方上门提货 + the import: the ASN carries the pickup, ready date, the client's plan (customer fields only) and its origin.
        $this->generateFromImport($cs, $import)->assertSessionHasNoErrors()->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['we_collect', 'booked', 'requested', 1, 'client', $import->id, $ready, 'Dock 3, 8-4', [], 2], [
            $asn->inbound_transport, $asn->status, $asn->collection_status, $asn->collection_version, $asn->collection_requested_via, $asn->collection_import_id,
            $asn->collection_ready_date->toDateString(), $asn->collection_notes, $asn->collection_packages, $asn->lines()->count(),
        ]);
        $this->assertEquals(['name' => 'Factory', 'phone' => '0400 000 009', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'], $asn->collection_address);
        $this->assertEqualsCanonicalizing(AsnService::PREFERENCE_KEYS, array_keys($asn->collection_preference));
        $this->assertSame(['own_fleet', 7500, $user->id], [$asn->collection_preference['source'], $asn->collection_preference['customer_price_cents'], $asn->collection_preference['chosen_by']]);

        $event = OutboxEvent::query()->where('event_name', 'asn.collection_requested')->sole();
        $this->assertSame(['client', 'own_fleet', 7500, $asn->id, $asn->job_id], [$event->payload['requested_via'], $event->payload['client_preference']['source'], $event->payload['client_preference']['customer_price_cents'], $event->payload['asn_id'], $event->job_id]);
        $this->assertArrayNotHasKey('cost_cents', $event->payload['client_preference']);
        $this->assertCount(2, $event->payload['lines'], 'the goods lines carry weight + dims — Transport prices them');

        // The ASN page shows the client's plan and where the request came from; the edit form warns about re-confirmation.
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.collection.fields.client_choice'))->assertSee('$75.00')->assertSee(__('warehouse.asns.collection.origins.client'))
            ->assertSee(route('orders.imports.show', $import->id), false)->assertSee(__('warehouse.asns.collection.edit_client_hint'));
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()->assertDontSee('data-import-id="'.$import->id.'"', false);

        // A staff edit on the ASN page keeps the client's plan and origin (the re-quote still honours it); 改为客户自送 ends the request.
        $this->actingAs($cs)->put(route('warehouse.asns.collection.update', $asn), [
            'collection' => ['name' => 'Factory', 'phone' => '0400 000 009', 'address' => '11 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
            'collection_ready_date' => $ready,
        ])->assertSessionHasNoErrors();
        $asn->refresh();
        $this->assertSame([2, 'client', $import->id, 'own_fleet', '11 Supplier Rd'], [$asn->collection_version, $asn->collection_requested_via, $asn->collection_import_id, $asn->collection_preference['source'], $asn->collection_address['address']]);
        $this->assertSame('own_fleet', OutboxEvent::query()->where('event_name', 'asn.collection_requested')->orderByDesc('id')->first()->payload['client_preference']['source']);
        $this->actingAs($cs)->delete(route('warehouse.asns.collection.destroy', $asn))->assertSessionHasNoErrors();
        $asn->refresh();
        $this->assertSame(['client_delivers', null, null, null], [$asn->inbound_transport, $asn->collection_preference, $asn->collection_requested_via, $asn->collection_import_id]);
    }

    public function test_a_refused_collection_rolls_the_whole_generation_back_and_another_clients_import_is_refused(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $import = $this->uploadCollection($user, $warehouse);
        $this->confirmCollection($user, $import, $this->planKey('own_fleet', 'standard'))->assertSessionHasNoErrors();
        $orderIds = $this->importedOrderIds($import);
        $cs = $this->staff('customer_service');

        // The ready date the client gave has passed by the time customer service reviews it: Chinese error, NO ASN, orders untouched, input kept.
        $this->generateFromImport($cs, $import, ['collection_ready_date' => today()->subDay()->toDateString()])
            ->assertRedirect(route('orders.inbound.index'))
            ->assertSessionHasErrors(['inbound' => __('warehouse.asns.collection.errors.ready_date_past')])
            ->assertSessionHasInput('collection_import_id', $import->id);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'asn.collection_requested')->count());
        $this->assertSame(0, OrderLine::query()->whereIn('order_id', $orderIds)->whereNotNull('asn_line_id')->count());
        $this->assertEqualsCanonicalizing($orderIds, app(OrderInboundService::class)->candidates($client->id)->pluck('id')->all(), 'the orders still wait in 待建预报');
        $this->actingAs($cs)->get(route('orders.inbound.index'))->assertOk()
            ->assertSee(__('warehouse.asns.collection.errors.ready_date_past'))->assertSee('name="collection_import_id" value="'.$import->id.'"', false)->assertDontSee('id="collection-fields" hidden disabled', false);

        // Another client's portal import cannot lend its plan: refused in Chinese, still no ASN.
        $other = $this->clientUser($this->client());
        $otherImport = $this->uploadCollection($other, $warehouse);
        $this->confirmCollection($other, $otherImport, $this->planKey('own_fleet', 'standard'))->assertSessionHasNoErrors();
        $this->generateFromImport($cs, $import, ['collection_import_id' => $otherImport->id])
            ->assertSessionHasErrors(['inbound' => __('orders.inbound.errors.collection_import_invalid', ['id' => $otherImport->id])]);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());

        // The right import and a valid date go through.
        $this->generateFromImport($cs, $import)->assertSessionHasNoErrors();
        $this->assertSame('client', Asn::query()->withoutGlobalScopes()->sole()->collection_requested_via);
    }

    public function test_staff_request_a_collection_without_an_import_and_the_asn_import_card_warns_about_client_requests(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $cs = $this->staff('customer_service');
        $import = $this->uploadCollection($user, $warehouse);
        $this->confirmCollection($user, $import, $this->planKey('own_fleet', 'standard'))->assertSessionHasNoErrors();
        $portalOrderIds = $this->importedOrderIds($import);
        $plain = app(OrderCreationService::class)->create([
            'client_id' => $client->id, 'order_type' => 'from_stock', 'consignment_mark' => 'MK-P', 'deliver_to_name' => 'Shop P', 'deliver_to_phone' => '0400 000 000',
            'deliver_to_address' => '1 High St', 'deliver_to_suburb' => 'Richmond', 'deliver_to_state' => 'VIC', 'deliver_to_postcode' => '3121', 'deliver_to_address_type' => 'business',
            'requested_date' => today()->addDays(14)->toDateString(), 'service_level' => 'standard',
            'lines' => [['description_en' => 'Kettle', 'package_type' => 'carton', 'carton_qty' => 4, 'actual_weight_kg' => 20, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
        ], $cs->id, 'manual');

        // awaitingAsn flags the orders of the client's collection request; the ASN page's import card warns on exactly those rows.
        $rows = collect(app(OrderService::class)->awaitingAsn($client->id))->keyBy('order_id');
        $this->assertSame([true, true, false], [$rows[$portalOrderIds[0]]['collection_requested'], $rows[$portalOrderIds[1]]['collection_requested'], $rows[$plain->id]['collection_requested']]);
        $existing = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $page = $this->actingAs($cs)->get(route('warehouse.asns.show', $existing))->assertOk()->assertSee(__('warehouse.asns.import_orders_title'));
        $this->assertSame(2, substr_count($page->getContent(), e(__('warehouse.asns.import_orders_collection_warning'))));

        // Staff 我方上门提货 without a portal import: requested by staff, no client preference, no import id.
        $this->actingAs($cs)->from(route('orders.inbound.index'))->post(route('orders.inbound.store'), [
            'order_ids' => [$plain->id], 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'inbound_transport' => 'we_collect',
            'collection' => ['name' => 'Supplier', 'phone' => '', 'address' => '2 Yard St', 'suburb' => 'Altona', 'state' => 'VIC', 'postcode' => '3018', 'type' => 'business'],
            'collection_ready_date' => today()->addDay()->toDateString(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['we_collect', 'staff', null, null, 'Altona'], [$asn->inbound_transport, $asn->collection_requested_via, $asn->collection_preference, $asn->collection_import_id, $asn->collection_address['suburb']]);
        $event = OutboxEvent::query()->where('event_name', 'asn.collection_requested')->sole();
        $this->assertSame(['staff', null], [$event->payload['requested_via'], $event->payload['client_preference']]);

        // 客户自送 (the default) ignores stray pickup fields entirely.
        $this->actingAs($cs)->from(route('orders.inbound.index'))->post(route('orders.inbound.store'), [
            'order_ids' => $portalOrderIds, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'inbound_transport' => 'client_delivers',
            'collection' => ['state' => 'MARS'], 'collection_import_id' => 'x',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(['client_delivers', null], [Asn::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->inbound_transport, Asn::query()->withoutGlobalScopes()->latest('id')->firstOrFail()->collection_requested_via]);
    }
}
