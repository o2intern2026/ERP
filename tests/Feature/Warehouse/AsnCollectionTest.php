<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Services\TransportOptionService;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\InboundService;
use App\Support\Contracts\RateService;
use App\Support\Contracts\TransportOptionService as TransportOptionServiceContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 预报单 到仓方式 我方上门提货 (lead decision 2026-09-14, CHANGE_REQUESTS #124): the coordinator asks Transport to collect the goods at the
 * client's pickup address and bring them to our warehouse. Request → shipment (no order, receiver = our dock) → final quote →
 * the dispatcher confirms → TR-* freight on the ASN's Job, no outbound handling → booking → the driver's POD at our dock marks the
 * ASN arrived; a failed collection flags the ASN and raises the exception; edits after booking are the dispatcher's; 改为客户自送
 * before booking cancels the shipment; a 300 kg pallet sets the pickup-side tailgate.
 */
class AsnCollectionTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    /** Own-fleet fixed cost the stub adapter answers with — a $75.00 collection. */
    public const OWN_FLEET_COST = 7500;

    protected function setUp(): void
    {
        parent::setUp();
        $own = Carrier::query()->create(['code' => 'OWN-COL', 'name' => 'Edward Own Fleet', 'status' => 'active']);
        CarrierService::query()->create(['carrier_id' => $own->id, 'source' => 'own_fleet', 'service_level' => 'standard', 'default_eta_days' => 1, 'active' => true]);

        $service = new TransportOptionService(
            [$this->ownFleetAdapter()],
            app(ShipmentQuoteRequestFactory::class), app(RateService::class), app(ExceptionService::class), app(QuoteSelectionService::class),
        );
        $this->app->instance(TransportOptionService::class, $service);
        $this->app->instance(TransportOptionServiceContract::class, $service);
    }

    public function test_collection_request_runs_from_the_asn_to_the_pod_at_our_dock_and_bills_freight_only_on_the_asns_job(): void
    {
        Storage::fake('local');
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $client = $this->client(['default_markup_percent' => 0, 'invoice_mode' => 'per_job', 'name' => 'Collect Co']);
        $this->freightCard($client);

        // The create form: 到仓方式 = 我方上门提货 with the pickup address, ready date and two declared packages (a 300 kg pallet → tailgate).
        $this->actingAs($cs)->get(route('warehouse.asns.create'))->assertOk()->assertSee(__('warehouse.asns.collection.modes.we_collect'))->assertSee('name="inbound_transport"', false);
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $this->form($client, $warehouse))->assertSessionHasNoErrors()->assertRedirect();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['we_collect', 'booked', 'requested', 1, 'Laverton', '3028'], [$asn->inbound_transport, $asn->status, $asn->collection_status, $asn->collection_version, $asn->collection_address['suburb'], $asn->collection_address['postcode']]);
        $this->assertCount(2, $asn->collection_packages);

        $event = OutboxEvent::query()->where('event_name', 'asn.collection_requested')->sole();
        $this->assertSame([$asn->id, $asn->asn_no, $client->id, $asn->job_id, $warehouse->id, 1, 'standard'], [$event->payload['asn_id'], $event->payload['asn_no'], $event->payload['client_id'], $event->payload['job_id'], $event->payload['warehouse_id'], $event->payload['activity_version'], $event->payload['service_level']]);
        $this->assertSame('3175', $event->payload['warehouse']['postcode']);
        $this->assertSame('business', $event->payload['collection_address']['type']);
        $this->assertSame($asn->job_id, $event->job_id);

        // Transport opens ONE inbound collection shipment: no order, sender = pickup, receiver = our warehouse, items = the packages, final stage at once.
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->where('asn_id', $asn->id)->sole();
        $this->assertSame([null, 'inbound_collection', 'quoted', true, $asn->job_id, $client->id, 1], [$shipment->order_id, $shipment->shipment_type, $shipment->status, $shipment->tailgate_required, $shipment->job_id, $shipment->client_id, $shipment->asn_activity_version]);
        $this->assertSame('SHP-'.$asn->asn_no, $shipment->shipment_no);
        $this->assertSame(0, TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'preliminary')->count());
        $final = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->sole();
        $this->assertSame(self::OWN_FLEET_COST, (int) $final->customer_price_cents);
        $request = $final->raw_response['_quote_request'];
        $this->assertSame(['9 Supplier Rd', 'Laverton', 'VIC', '3028', 'Factory'], [$request['sender']['address'], $request['sender']['suburb'], $request['sender']['state'], $request['sender']['postcode'], $request['sender']['name']]);
        $this->assertSame([$warehouse->name, '1 Depot Road', 'Dandenong South', 'VIC', '3175'], [$request['receiver']['name'], $request['receiver']['address'], $request['receiver']['suburb'], $request['receiver']['state'], $request['receiver']['postcode']]);
        $this->assertSame([['pallet', 1, 300.0], ['carton', 2, 10.0]], array_map(fn (array $i) => [$i['description'], $i['qty'], (float) $i['weight_kg']], $request['items']));
        $this->assertSame('3028', $request['zone']);
        $this->assertSame(0, Charge::query()->count());

        // The ASN page: status, no plan yet; the dispatcher confirms the plan on the shipment page (no client choice in v1).
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.collection.statuses.requested'))->assertSee(__('warehouse.asns.collection.plan_pending'))->assertSee('Laverton')->assertSee(__('warehouse.asns.collection.edit'));
        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()->assertSee($asn->asn_no)->assertSee(__('transport.shipments.collection_header'))->assertSee(__('transport.quotes.confirm'));
        $this->actingAs($dispatcher)->post(route('transport.shipments.quotes.select', [$shipment, $final]))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('quote_confirmed', $shipment->fresh()->status);

        $confirmed = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $this->assertSame([$asn->id, null, null, 'inbound_collection', true, '3028'], [$confirmed->payload['asn_id'], $confirmed->payload['order_id'], $confirmed->payload['order_type'], $confirmed->payload['shipment_type'], $confirmed->payload['tailgate_required'], $confirmed->payload['zone']]);
        $this->assertArrayNotHasKey('lines', $confirmed->payload);

        // Billing: freight + tailgate + fuel on the ASN's Job, keyed by the shipment; no outbound handling code. Warehouse copies the plan back.
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $charges = Charge::query()->with('chargeCode')->get();
        $this->assertEquals(['TR-DELIVERY-BASE' => self::OWN_FLEET_COST, 'TR-TAILGATE' => 4500, 'TR-FUEL' => 750], $charges->groupBy('chargeCode.code')->map(fn ($c) => (int) $c->sum('amount_cents'))->all());
        $this->assertTrue($charges->every(fn (Charge $c) => $c->job_id === $asn->job_id && $c->client_id === $client->id && $c->source_activity_id === "shipment:{$shipment->id}"));
        $this->assertSame(0, $charges->filter(fn (Charge $c) => str_starts_with($c->chargeCode->code, 'WH-'))->count());

        $asn->refresh();
        $this->assertSame(['confirmed', $shipment->id, self::OWN_FLEET_COST, 'own_fleet', 'Edward Own Fleet'], [$asn->collection_status, $asn->collection_shipment_id, $asn->collection_plan['customer_price_cents'], $asn->collection_plan['source'], $asn->collection_plan['carrier_name']]);
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()
            ->assertSee(__('warehouse.asns.collection.statuses.confirmed'))->assertSee('$75.00')->assertSee(route('transport.shipments.show', $shipment))->assertSee($shipment->shipment_no);

        // Booking → the ASN shows 已订舱; from now on the request is the dispatcher's.
        $this->actingAs($dispatcher)->post(route('transport.shipments.book', $shipment))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('booked', $shipment->fresh()->status);
        $booked = OutboxEvent::query()->where('event_name', 'shipment.booked')->sole();
        $this->assertSame([$asn->id, null, 'inbound_collection'], [$booked->payload['asn_id'], $booked->payload['order_id'], $booked->payload['shipment_type']]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('booked', $asn->fresh()->collection_status);
        $this->assertSame($shipment->fresh()->booking_ref, $asn->fresh()->collection_plan['booking_ref']);

        $page = $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.collection.locked_hint'));
        $page->assertDontSee(__('warehouse.asns.collection.switch_to_client'));
        $this->actingAs($cs)->put(route('warehouse.asns.collection.update', $asn), $this->collectionFields())
            ->assertSessionHasErrors(['collection' => __('warehouse.asns.collection.errors.locked', ['no' => $asn->asn_no])]);
        $this->actingAs($cs)->delete(route('warehouse.asns.collection.destroy', $asn))
            ->assertSessionHasErrors(['collection' => __('warehouse.asns.collection.errors.locked', ['no' => $asn->asn_no])]);
        $this->assertSame('we_collect', $asn->fresh()->inbound_transport);

        // The driver collects and signs off at our dock: the POD marks the 预报单 arrived; the Orders consumer steps aside (no order); nothing dies.
        $driver = $this->staff('transport_operator');
        $run = app(DeliveryRunService::class)->create(today()->toDateString(), $driver->id, 'VAN-COL');
        $stop = app(DeliveryRunService::class)->addShipment($run, $shipment->fresh());
        $this->actingAs($driver)->get(route('transport.driver'))->assertOk()->assertSee(__('transport.driver.pickup_from'))->assertSee('9 Supplier Rd')->assertSee(__('transport.driver.deliver_to_warehouse'))->assertSee('1 Depot Road');
        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $stop), [
            'recipient_name' => 'Dock Supervisor', 'signature_data' => $this->signatureData(), 'photos' => [UploadedFile::fake()->image('dock.jpg', 120, 80)],
        ])->assertRedirect(route('transport.driver'))->assertSessionHasNoErrors();
        $pod = OutboxEvent::query()->where('event_name', 'delivery.pod_captured')->sole();
        $this->assertSame([$asn->id, null, 'inbound_collection'], [$pod->payload['asn_id'], $pod->payload['order_id'], $pod->payload['shipment_type']]);
        app(OutboxDispatcher::class)->dispatchDue();

        $asn->refresh();
        $this->assertSame(['arrived', 'delivered', 'delivered'], [$asn->status, $asn->collection_status, $shipment->fresh()->status]);
        $this->assertNotNull($asn->arrived_at);
        $this->assertSame(0, OutboxEvent::query()->whereIn('status', ['failed', 'dead'])->count(), 'every consumer accepted the collection events');
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asn_statuses.arrived'))->assertSee(__('warehouse.asns.collection.statuses.delivered'));

        // A replay of the POD changes nothing (the ASN stays arrived once).
        $arrivedAt = $asn->arrived_at;
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertTrue($arrivedAt->equalTo($asn->fresh()->arrived_at));
    }

    public function test_a_failed_collection_flags_the_asn_and_raises_the_delivery_exception_without_an_order(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $client = $this->client(['default_markup_percent' => 0]);
        $this->freightCard($client);
        $asn = $this->requestedAsn($cs, $client, $warehouse);
        $shipment = $this->confirmedAndBooked($asn);

        $driver = $this->staff('transport_operator');
        $run = app(DeliveryRunService::class)->create(today()->toDateString(), $driver->id, 'VAN-COL');
        $stop = app(DeliveryRunService::class)->addShipment($run, $shipment);
        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop), ['failure_reason' => 'access_blocked'])->assertRedirect()->assertSessionHasNoErrors();
        $failed = OutboxEvent::query()->where('event_name', 'delivery.failed')->sole();
        $this->assertSame([$asn->id, null], [$failed->payload['asn_id'], $failed->payload['order_id']]);
        app(OutboxDispatcher::class)->dispatchDue();

        $this->assertSame(['booked', 'failed'], [$asn->fresh()->status, $asn->fresh()->collection_status]);
        $exception = ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'delivery_failed')->sole();
        $this->assertSame([$asn->job_id, $client->id, null, 'shipment', $shipment->id], [$exception->job_id, $exception->client_id, $exception->order_id, $exception->source_type, $exception->source_id]);
        $this->assertSame(0, OutboxEvent::query()->whereIn('status', ['failed', 'dead'])->count());
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.collection.statuses.failed'));
    }

    public function test_switching_back_to_client_delivers_cancels_the_unbooked_shipment_and_a_re_request_reuses_it(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $client = $this->client(['default_markup_percent' => 0]);
        $this->freightCard($client);
        // Light cartons only: no tailgate at pickup.
        $asn = $this->requestedAsn($cs, $client, $warehouse, [['package_type' => 'carton', 'qty' => 3, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]]);
        $shipment = Shipment::query()->where('asn_id', $asn->id)->sole();
        $this->assertSame(['quoted', false], [$shipment->status, $shipment->tailgate_required]);

        $this->actingAs($cs)->delete(route('warehouse.asns.collection.destroy', $asn))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['client_delivers', null], [$asn->fresh()->inbound_transport, $asn->fresh()->collection_status]);
        $cancelled = OutboxEvent::query()->where('event_name', 'asn.collection_cancelled')->sole();
        $this->assertSame($asn->id, $cancelled->payload['asn_id']);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('booking_cancelled', $shipment->fresh()->status);
        $this->assertSame(['booking_cancelled'], TransportQuote::query()->where('shipment_id', $shipment->id)->pluck('status')->unique()->values()->all());
        $this->actingAs($cs)->get(route('warehouse.asns.show', $asn))->assertOk()->assertSee(__('warehouse.asns.collection.modes.client_delivers'))->assertSee(__('warehouse.asns.collection.switch_to_collect'));

        // 改为我方上门提货 again (from the ASN page): version 2, the SAME shipment reopens and is re-quoted.
        $this->actingAs($cs)->put(route('warehouse.asns.collection.update', $asn), $this->collectionFields(['collection_packages' => [['package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400]]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['we_collect', 'requested', 2], [$asn->fresh()->inbound_transport, $asn->fresh()->collection_status, $asn->fresh()->collection_version]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(1, Shipment::query()->where('asn_id', $asn->id)->count());
        $reopened = $shipment->fresh();
        $this->assertSame(['quoted', true, 2], [$reopened->status, $reopened->tailgate_required, $reopened->asn_activity_version]);
        $this->assertSame(1, TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'quoted')->count());

        // A replay of the older request (version 1) is ignored.
        $old = OutboxEvent::query()->where('event_name', 'asn.collection_requested')->orderBy('id')->first();
        $old->update(['status' => 'pending', 'available_at' => now()]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(2, $shipment->fresh()->asn_activity_version);
    }

    public function test_a_re_request_after_confirmation_re_quotes_and_the_new_freight_reverses_the_old(): void
    {
        $cs = $this->staff('customer_service');
        $warehouse = $this->warehouse();
        $client = $this->client(['default_markup_percent' => 0]);
        $this->freightCard($client);
        $asn = $this->requestedAsn($cs, $client, $warehouse);
        $shipment = Shipment::query()->where('asn_id', $asn->id)->sole();
        $dispatcher = $this->staff('dispatcher');
        $first = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->sole();
        $this->actingAs($dispatcher)->post(route('transport.shipments.quotes.select', [$shipment, $first]))->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->where('payload->activity_version', 1)->count());
        $this->assertEquals(['TR-DELIVERY-BASE' => 1, 'TR-TAILGATE' => 1, 'TR-FUEL' => 1], Charge::query()->with('chargeCode')->get()->groupBy('chargeCode.code')->map->count()->all());

        // The coordinator changes the packages (light cartons only): version 2, the same shipment goes back to quoting, the plan is gone from the ASN.
        $this->actingAs($cs)->put(route('warehouse.asns.collection.update', $asn), $this->collectionFields(['collection_packages' => [['package_type' => 'carton', 'qty' => 3, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['requested', null, 2], [$asn->fresh()->collection_status, $asn->fresh()->collection_plan, $asn->fresh()->collection_version]);
        app(OutboxDispatcher::class)->dispatchDue();
        $reopened = $shipment->fresh();
        $this->assertSame(['quoted', false, 2, null, 1], [$reopened->status, $reopened->tailgate_required, $reopened->asn_activity_version, $reopened->selected_quote_id, Shipment::query()->where('asn_id', $asn->id)->count()]);
        $this->assertSame('requoted', $first->fresh()->status);

        // The dispatcher confirms the new plan: version 2 freight (no tailgate now), the version-1 charges reversed — never deleted.
        $second = TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'quoted')->sole();
        $this->actingAs($dispatcher)->post(route('transport.shipments.quotes.select', [$shipment->fresh(), $second]))->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $charges = Charge::query()->with('chargeCode')->get();
        $live = $charges->where('status', 'pending')->whereNull('reversal_of_charge_id');
        $this->assertEquals(['TR-DELIVERY-BASE' => [2, self::OWN_FLEET_COST], 'TR-FUEL' => [2, 750]], $live->groupBy('chargeCode.code')->map(fn ($c) => [(int) $c->first()->activity_version, (int) $c->sum('amount_cents')])->all());
        $this->assertSame(3, $charges->where('status', 'reversed')->where('activity_version', 1)->whereNull('reversal_of_charge_id')->count(), 'the first confirmation freight, tailgate and fuel are reversed');
        $this->assertSame(3, $charges->whereNotNull('reversal_of_charge_id')->count(), 'one reversal twin each — never a deletion');
        $this->assertSame(8, $charges->count(), 'three version-1 originals, their three twins, two version-2 charges');
        $this->assertSame(['confirmed', self::OWN_FLEET_COST], [$asn->fresh()->collection_status, $asn->fresh()->collection_plan['customer_price_cents']]);
    }

    public function test_validation_and_roles(): void
    {
        $warehouse = $this->warehouse();
        $client = $this->client();
        $cs = $this->staff('customer_service');

        // Past ready date; no packages on the create form; incomplete address.
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $this->form($client, $warehouse, ['collection_ready_date' => today()->subDay()->toDateString()]))->assertSessionHasErrors('collection_ready_date');
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $this->form($client, $warehouse, ['collection_packages' => []]))->assertSessionHasErrors('collection_packages');
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $this->form($client, $warehouse, ['collection' => ['name' => 'X', 'address' => '1 St']]))->assertSessionHasErrors(['collection.suburb', 'collection.state', 'collection.postcode']);
        $this->assertSame(0, Asn::query()->withoutGlobalScopes()->count());

        // A plain 客户自送 ASN ignores the collection fields; an operator may not request a collection.
        $this->actingAs($cs)->post(route('warehouse.asns.store'), $this->form($client, $warehouse, ['inbound_transport' => 'client_delivers']))->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['client_delivers', null, 0], [$asn->inbound_transport, $asn->collection_status, $asn->collection_version]);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'asn.collection_requested')->count());
        $this->actingAs($this->staff('warehouse_operator'))->put(route('warehouse.asns.collection.update', $asn), $this->collectionFields())->assertForbidden();
        $this->actingAs($this->staff('warehouse_operator'))->get(route('warehouse.asns.show', $asn))->assertOk()->assertDontSee(__('warehouse.asns.collection.switch_to_collect'));

        // An arrived ASN can no longer request a collection (the Chinese refusal lands under `collection`).
        app(AsnService::class)->markArrived($asn);
        $this->actingAs($cs)->put(route('warehouse.asns.collection.update', $asn), $this->collectionFields())
            ->assertSessionHasErrors(['collection' => __('warehouse.asns.collection.errors.asn_not_booked', ['no' => $asn->asn_no])]);
    }

    /**
     * CHANGE_REQUESTS #125 (review TEST-1): Warehouse itself whitelists a client preference to the customer snapshot keys — whatever a caller
     * of InboundService::requestCollection hands over (a quote row, a raw option carrying cost / markup), neither asns.collection_preference
     * nor the asn.collection_requested payload ever holds anything else. A staff edit without the key keeps the stored plan.
     */
    public function test_request_collection_keeps_only_the_customer_snapshot_of_a_client_preference(): void
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $chooser = $this->clientUser($client);
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $snapshot = ['carrier_id' => 7, 'carrier_name' => 'Edward Own Fleet', 'source' => 'own_fleet', 'service_level' => 'standard', 'customer_price_cents' => 7500, 'eta_days' => 1,
            'is_recommended' => true, 'is_cheapest' => true, 'is_fastest' => false, 'chosen_at' => now()->toIso8601String(), 'chosen_by' => $chooser->id];
        $fields = $this->collectionFields();

        $result = app(InboundService::class)->requestCollection($asn->id, [
            'address' => $fields['collection'], 'ready_date' => $fields['collection_ready_date'], 'packages' => $fields['collection_packages'], 'notes' => null,
            'client_preference' => $snapshot + ['cost_cents' => 6000, 'markup_percent' => 25, 'margin_cents' => 1500, 'key' => 'own_fleet|standard|7', 'raw_response' => ['cost' => 6000]],
            'requested_via' => 'client', 'import_id' => 42,
        ], null);

        $asn->refresh();
        $this->assertSame(['asn_id' => $asn->id, 'collection_version' => 1], $result);
        $this->assertEqualsCanonicalizing(AsnService::PREFERENCE_KEYS, array_keys($asn->collection_preference));
        $this->assertEquals($snapshot, $asn->collection_preference);
        $this->assertSame(['client', 42], [$asn->collection_requested_via, $asn->collection_import_id]);
        $payload = OutboxEvent::query()->where('event_name', 'asn.collection_requested')->sole()->payload;
        $this->assertEqualsCanonicalizing(AsnService::PREFERENCE_KEYS, array_keys($payload['client_preference']));
        $this->assertStringNotContainsString('6000', json_encode($payload['client_preference']));

        // A staff edit on the ASN page (no client_preference key) keeps the whitelisted plan and origin.
        app(AsnService::class)->setCollection($asn, ['address' => $fields['collection'], 'ready_date' => today()->addDays(3)->toDateString(), 'packages' => $fields['collection_packages']], null);
        $asn->refresh();
        $this->assertSame([2, 'client', 42], [$asn->collection_version, $asn->collection_requested_via, $asn->collection_import_id]);
        $this->assertEquals($snapshot, $asn->collection_preference);
    }

    /** A collection requested on the create form and handed to Transport (dispatched once). */
    private function requestedAsn($actor, Client $client, Warehouse $warehouse, ?array $packages = null): Asn
    {
        $this->actingAs($actor)->post(route('warehouse.asns.store'), $this->form($client, $warehouse, $packages === null ? [] : ['collection_packages' => $packages]))->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();

        return Asn::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** The dispatcher confirms the final own-fleet quote and books it; Warehouse has copied the progress back. */
    private function confirmedAndBooked(Asn $asn): Shipment
    {
        $shipment = Shipment::query()->where('asn_id', $asn->id)->sole();
        $final = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->sole();
        $dispatcher = $this->staff('dispatcher');
        $this->actingAs($dispatcher)->post(route('transport.shipments.quotes.select', [$shipment, $final]))->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->actingAs($dispatcher)->post(route('transport.shipments.book', $shipment))->assertSessionHasNoErrors();
        app(OutboxDispatcher::class)->dispatchDue();
        $this->assertSame('booked', $asn->fresh()->collection_status);

        return $shipment->fresh();
    }

    /** @return array<string, mixed> the ASN create form with 我方上门提货 */
    private function form(Client $client, Warehouse $warehouse, array $overrides = []): array
    {
        return array_replace([
            'client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'expected_date' => today()->addDays(3)->toDateString(),
            'inbound_transport' => 'we_collect', 'unplanned' => 0,
        ], $this->collectionFields(), $overrides);
    }

    /** @return array<string, mixed> the pickup party, ready date and packages (a 300 kg pallet + two 10 kg cartons) */
    private function collectionFields(array $overrides = []): array
    {
        return array_replace([
            'collection' => ['name' => 'Factory', 'phone' => '0400 000 001', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
            'collection_ready_date' => today()->addDays(2)->toDateString(),
            'collection_notes' => 'Dock 3, 8-4',
            'collection_packages' => [
                ['package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400],
                ['package_type' => 'carton', 'qty' => 2, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
            ],
        ], $overrides);
    }

    private function freightCard(Client $client): RateCard
    {
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-TAILGATE'], 'pricing_mode' => 'fixed', 'rate_cents' => 4500, 'threshold_json' => ['tailgate_weight_kg' => 25]]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 10]);

        return $card;
    }

    private function signatureData(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';
    }

    private function ownFleetAdapter(): CarrierAdapter
    {
        return new class implements CarrierAdapter
        {
            public function source(): string
            {
                return 'own_fleet';
            }

            public function capabilities(): array
            {
                return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => false, 'tracking' => 'none', 'pod' => 'manual'];
            }

            public function quote(array $request): array
            {
                return [[
                    'service_code' => 'own.standard', 'service_name' => 'own.standard', 'service_level' => 'standard',
                    'cost_cents' => AsnCollectionTest::OWN_FLEET_COST, 'eta_days' => 1, 'pickup_dates' => [], 'raw' => ['pricing_mode' => 'fixed'],
                ]];
            }

            public function book(array $request, string $serviceCode, array $options = []): array
            {
                return ['booking_ref' => 'COL-1', 'tracking_number' => null, 'label_path' => null, 'status' => 'booked', 'raw' => []];
            }

            public function cancel(string $bookingRef): bool
            {
                return true;
            }

            public function label(string $bookingRef): ?string
            {
                return null;
            }

            public function tracking(string $bookingRef): array
            {
                return [];
            }
        };
    }
}
