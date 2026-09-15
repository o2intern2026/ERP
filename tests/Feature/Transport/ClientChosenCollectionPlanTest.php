<?php

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\RateCard;
use App\Modules\Billing\Models\RateItem;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\OrderImport;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxDispatcher;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\PortalCollectionFlow;
use Tests\Support\StubCarrierAdapter;
use Tests\TestCase;

/**
 * 客户自选提货方案 (CHANGE_REQUESTS #125), Transport side (C as integrator in the X2 zone): the plan the client ticked in the portal is the
 * reference for the collection shipment's final quote. The portal estimate is the very request Transport builds for the ASN made from
 * the same orders; the same price is confirmed in the client's name and billed on the ASN's Job; a price beyond the tolerance waits
 * for the client, who re-confirms on its 预报入库 page; a chooser who left falls back to the preferenceActor rule (system).
 */
class ClientChosenCollectionPlanTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, PortalCollectionFlow, RefreshDatabase;

    private const OWN_FLEET_COST = 7500;

    /** A third-party carrier cost: its client price is cost × (1 + the client's markup), so a test with a markup tells cost and price apart. */
    private const DEMO_FREIGHT_COST = 8130;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindStubCarriers([
            'own_fleet' => ['code' => 'OWN-CCP', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => self::OWN_FLEET_COST],
            'karrio' => ['code' => 'KAR-CCP', 'name' => 'Demo Freight', 'level' => 'express', 'cost' => self::DEMO_FREIGHT_COST],
        ]);
    }

    public function test_the_portal_estimate_is_the_request_transport_builds_and_the_same_price_is_confirmed_as_the_client(): void
    {
        Storage::fake('local');
        [$client, $user, $warehouse, $import] = $this->clientChoseOwnFleet();
        $estimate = collect(StubCarrierAdapter::$requests)->where('description', 'estimate')->last();

        $this->generateFromImport($this->staff('customer_service'), $import)->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();

        // Same request, key for key and in the same order (sender, receiver, items, zone, tailgate at pickup, ready date) — only the description differs.
        $built = app(ShipmentQuoteRequestFactory::class)->build($shipment, 'final');
        $this->assertNotNull($built);
        $this->assertSame(Arr::except($built, ['description']), Arr::except($estimate, ['description']));
        $this->assertSame(['estimate', $shipment->shipment_no], [$estimate['description'], $built['description']]);
        $this->assertSame([true, '3028', today()->addDays(2)->toDateString()], [$estimate['tailgate_pickup'], $estimate['zone'], $estimate['requested_date']]);
        $this->assertSame(Arr::except($built, ['description']), Arr::except(collect(StubCarrierAdapter::$requests)->where('description', $shipment->shipment_no)->last(), ['description']), 'what the carrier was asked for the shipment');

        // Same price → confirmed automatically in the client's name at the final stage, nobody clicked.
        $shipment->refresh();
        $selected = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'selected')->sole();
        $this->assertSame(['quote_confirmed', $selected->id, 'client', $user->id, self::OWN_FLEET_COST], [$shipment->status, $shipment->selected_quote_id, $selected->selected_by, $selected->selected_by_user_id, (int) $selected->customer_price_cents]);
        $confirmed = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $this->assertSame(['client', $user->id, $asn->id, 'inbound_collection'], [$confirmed->payload['confirmed_by_type'], $confirmed->payload['confirmed_by'], $confirmed->payload['asn_id'], $confirmed->payload['shipment_type']]);

        // Billing: freight, fuel (and the pickup tailgate of the 300 kg pallet) on the ASN's Job; the ASN and the portal show who confirmed.
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
        $charges = Charge::query()->withoutGlobalScopes()->with('chargeCode')->get();
        $this->assertEquals(['TR-DELIVERY-BASE' => self::OWN_FLEET_COST, 'TR-TAILGATE' => 4500, 'TR-FUEL' => 750], $charges->groupBy('chargeCode.code')->map(fn ($c) => (int) $c->sum('amount_cents'))->all());
        $this->assertTrue($charges->every(fn (Charge $c) => $c->job_id === $asn->job_id && $c->client_id === $client->id));
        $this->assertSame(['confirmed', 'client'], [$asn->fresh()->collection_status, $asn->fresh()->collection_plan['confirmed_by_type']]);
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee(__('portal.asns.collection.fields.confirmed_by'))->assertSee(__('portal.asns.collection.confirmed_by.client'))->assertSee('$75.00')
            ->assertDontSee(__('portal.asns.collection.reconfirm_title'));
    }

    public function test_a_price_beyond_the_tolerance_waits_for_the_client_who_re_confirms_on_the_portal_asn_page(): void
    {
        Storage::fake('local');
        [, $user, , $import] = $this->clientChoseOwnFleet(20); // Demo Freight: 8130 cost × 1.2 = $97.56 client price; own fleet is priced at cost

        // The ASN is priced 40% above what the client chose (tolerance 10%): no confirmation, the shipment waits for the client.
        StubCarrierAdapter::$costs['own_fleet'] = 10500;
        $this->generateFromImport($this->staff('customer_service'), $import)->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();
        $this->assertSame(['quoted', null], [$shipment->status, $shipment->selected_quote_id]);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
        $quote = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', 'own_fleet')->sole();
        $thirdParty = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', 'karrio')->sole();
        $this->assertSame([self::DEMO_FREIGHT_COST, 9756], [(int) $thirdParty->cost_cents, (int) $thirdParty->customer_price_cents], 'cost and client price differ on this page');

        // The portal 预报入库 page lists the final quotes with client prices only and marks the client's earlier choice; the carrier cost figure never shows.
        $page = $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee(__('portal.asns.collection.reconfirm_title'))->assertSee(__('portal.asns.collection.your_choice'))->assertSee('$105.00')->assertSee('$75.00')->assertSee('$97.56')
            ->assertSee(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]), false)
            ->assertDontSee('8130')->assertDontSee('81.30')
            ->assertDontSee('cost_cents')->assertDontSee('markup')->assertDontSee('portal.asns.collection.');
        $visible = preg_replace(['/<script.*?<\/script>/s', '/<style.*?<\/style>/s', '/ style="[^"]*"/'], '', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/\b(cost|margin)\b/i', $visible);
        $this->assertStringNotContainsString('成本', $visible);

        // Another client's user never reaches the quote; staff do not use the portal route.
        $this->actingAs($this->clientUser($this->client()))->post(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]))->assertNotFound();
        $this->actingAs($this->staff('customer_service'))->post(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]))->assertForbidden();
        $this->assertSame('quoted', $shipment->fresh()->status);

        // The client confirms → quote_confirmed in its own name; the page then names the confirmed plan.
        $this->actingAs($user)->post(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]))
            ->assertRedirect(route('portal.asns.show', $asn).'#collection')->assertSessionHasNoErrors()->assertSessionHas('status', __('portal.asns.collection.confirmed', ['no' => $asn->asn_no]));
        $this->assertSame(['quote_confirmed', $quote->id, 'client', $user->id], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id, $quote->fresh()->selected_by, $quote->fresh()->selected_by_user_id]);
        app(OutboxDispatcher::class)->dispatchDue();
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee(__('portal.asns.collection.confirmed_by.client'))->assertSee('$105.00')->assertDontSee(__('portal.asns.collection.reconfirm_title'));
    }

    public function test_a_chooser_who_is_no_longer_active_leaves_the_confirmation_to_the_system(): void
    {
        Storage::fake('local');
        [, $user, , $import] = $this->clientChoseOwnFleet();
        User::query()->whereKey($user->id)->update(['is_active' => false]); // preferenceActor: only a still-active user of the client counts

        $this->generateFromImport($this->staff('customer_service'), $import)->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();
        $selected = TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'selected')->sole();
        $this->assertSame(['quote_confirmed', 'system', null], [$shipment->status, $selected->selected_by, $selected->selected_by_user_id]);
        $this->assertSame('system', OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole()->payload['confirmed_by_type']);
    }

    public function test_a_staff_edit_after_the_client_re_confirmed_another_plan_keeps_that_plan_instead_of_the_upload_time_choice(): void
    {
        Storage::fake('local');
        [, $user, , $import] = $this->clientChoseOwnFleet(); // own fleet $75.00 chosen; Demo Freight at $81.30 (no markup)
        $cs = $this->staff('customer_service');

        // Own fleet comes back at $105.00 (beyond 10%): the shipment waits, and the client explicitly confirms Demo Freight at $81.30 instead.
        StubCarrierAdapter::$costs['own_fleet'] = 10500;
        $this->generateFromImport($cs, $import)->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->dispatchOutbox();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();
        $this->assertSame('quoted', $shipment->status);
        $demo = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', 'karrio')->sole();
        $this->actingAs($user)->post(route('portal.asns.collection.quotes.confirm', [$asn, $demo->id]))->assertSessionHasNoErrors();
        $this->dispatchOutbox();
        $this->assertSame(['confirmed', 'karrio'], [$asn->fresh()->collection_status, $asn->fresh()->collection_plan['source']]);

        // Customer service changes only the ready date. Own fleet now prices within 10% of the upload-time $75.00, Demo Freight within 10% of
        // the $81.30 the client confirmed: the client's later explicit choice is re-confirmed in its name — never swapped back to own fleet.
        StubCarrierAdapter::$costs = ['own_fleet' => 7800, 'karrio' => 8300];
        $this->editReadyDate($cs, $asn, 3);
        $this->dispatchOutbox();
        $this->assertConfirmed($shipment, 'karrio', 8300, 'client', $user->id);
        $this->assertSame([2, 'karrio'], [(int) $shipment->fresh()->asn_activity_version, $asn->fresh()->collection_plan['source']]);
    }

    public function test_a_price_the_client_accepted_is_kept_on_a_re_request_and_automatic_confirmations_never_move_the_reference(): void
    {
        Storage::fake('local');
        [, $user, , $import] = $this->clientChoseOwnFleet();
        $cs = $this->staff('customer_service');

        // $105.00 against the $75.00 chosen on the upload: the client accepts it on the portal.
        StubCarrierAdapter::$costs['own_fleet'] = 10500;
        $this->generateFromImport($cs, $import)->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->dispatchOutbox();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();
        $own = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', 'own_fleet')->sole();
        $this->actingAs($user)->post(route('portal.asns.collection.quotes.confirm', [$asn, $own->id]))->assertSessionHasNoErrors();
        $this->dispatchOutbox();

        // A staff edit re-quotes the same $105.00: confirmed again in the client's name — it is not asked to accept a price it already accepted.
        $this->editReadyDate($cs, $asn, 3);
        $this->dispatchOutbox();
        $this->assertConfirmed($shipment, 'own_fleet', 10500, 'client', $user->id);

        // $110.00 (within 10% of $105.00): confirmed automatically.
        StubCarrierAdapter::$costs['own_fleet'] = 11000;
        $this->editReadyDate($cs, $asn, 4);
        $this->dispatchOutbox();
        $this->assertConfirmed($shipment, 'own_fleet', 11000, 'client', $user->id);

        // $118.00 is within 10% of the automatic $110.00 but not of the $105.00 the client accepted: the automatic confirmation did not move
        // the reference, so the price cannot creep up edit by edit — the client re-confirms on the portal.
        StubCarrierAdapter::$costs['own_fleet'] = 11800;
        $this->editReadyDate($cs, $asn, 5);
        $this->dispatchOutbox();
        $this->assertSame(['quoted', null], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id]);
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee(__('portal.asns.collection.reconfirm_title'))->assertSee('$118.00');
    }

    public function test_the_client_cannot_confirm_the_plan_of_a_collection_customer_service_requested(): void
    {
        Storage::fake('local');
        [, $user, , $import] = $this->clientChoseOwnFleet();

        // Generated as customer service's own request (no import link): no client plan, the quotes wait for the dispatcher / customer service (#124).
        $this->generateFromImport($this->staff('customer_service'), $import, ['collection_import_id' => ''])->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        $this->assertSame(['staff', null], [$asn->collection_requested_via, $asn->collection_preference]);
        $this->dispatchOutbox();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();
        $quote = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->where('source', 'own_fleet')->sole();
        $this->assertSame('quoted', $shipment->status);

        // The portal offers no table, and a hand-made POST for the client's own shipment is a 404 — nothing selected, nothing billed.
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertDontSee(__('portal.asns.collection.reconfirm_title'))
            ->assertDontSee(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]), false);
        $this->actingAs($user)->post(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]))->assertNotFound();
        $this->assertSame(['quoted', null, 'quoted', 0], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id, $quote->fresh()->status, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count()]);
    }

    private function dispatchOutbox(): void
    {
        app(OutboxDispatcher::class)->dispatchDue();
        app(OutboxDispatcher::class)->dispatchDue();
    }

    /** 修改 on the ASN page: only the ready date changes — a re-request (collection_version + 1) that re-quotes the shipment. */
    private function editReadyDate(User $staff, Asn $asn, int $days): void
    {
        $asn->refresh();
        $this->actingAs($staff)->put(route('warehouse.asns.collection.update', $asn), [
            'collection' => $asn->collection_address,
            'collection_ready_date' => today()->addDays($days)->toDateString(),
            'collection_notes' => $asn->collection_notes,
        ])->assertSessionHasNoErrors();
    }

    private function assertConfirmed(Shipment $shipment, string $source, int $priceCents, string $selectedBy, ?int $userId): void
    {
        $shipment->refresh();
        $selected = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'selected')->sole();
        $this->assertSame(['quote_confirmed', $selected->id, $source, $priceCents, $selectedBy, $userId], [
            $shipment->status, $shipment->selected_quote_id, $selected->source, (int) $selected->customer_price_cents, $selected->selected_by, $selected->selected_by_user_id,
        ]);
    }

    /** @return array{Client, User, Warehouse, OrderImport} a client whose 入库清单 asked us to collect and chose own fleet at $75.00 */
    private function clientChoseOwnFleet(int $markupPercent = 0): array
    {
        $client = $this->client(['default_markup_percent' => $markupPercent, 'invoice_mode' => 'per_job']);
        $this->freightCard($client);
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $import = $this->uploadCollection($user, $warehouse);
        $this->actingAs($user)->get(route('portal.asns.imports.show', $import))->assertOk()->assertSee('$75.00');
        $this->confirmCollection($user, $import, $this->planKey('own_fleet', 'standard'))->assertSessionHasNoErrors();
        $this->assertSame(self::OWN_FLEET_COST, $import->fresh()->errors['context']['inbound']['collection']['preference']['customer_price_cents']);

        return [$client, $user, $warehouse, $import];
    }

    private function freightCard(Client $client): void
    {
        $codes = ChargeCode::query()->pluck('id', 'code');
        $card = RateCard::query()->create(['client_id' => $client->id, 'name' => 'freight', 'version' => 1, 'effective_from' => today()->subDay(), 'status' => 'active']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-DELIVERY-BASE'], 'pricing_mode' => 'cost_plus']);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-TAILGATE'], 'pricing_mode' => 'fixed', 'rate_cents' => 4500, 'threshold_json' => ['tailgate_weight_kg' => 25]]);
        RateItem::query()->create(['rate_card_id' => $card->id, 'charge_code_id' => $codes['TR-FUEL'], 'pricing_mode' => 'percent', 'markup_percent' => 10]);
    }
}
