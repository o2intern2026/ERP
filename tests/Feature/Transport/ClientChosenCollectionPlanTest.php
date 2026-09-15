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

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindStubCarriers(['own_fleet' => ['code' => 'OWN-CCP', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => self::OWN_FLEET_COST]]);
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
        [, $user, , $import] = $this->clientChoseOwnFleet();

        // The ASN is priced 40% above what the client chose (tolerance 10%): no confirmation, the shipment waits for the client.
        StubCarrierAdapter::$costs['own_fleet'] = 10500;
        $this->generateFromImport($this->staff('customer_service'), $import)->assertSessionHasNoErrors();
        $asn = Asn::query()->withoutGlobalScopes()->sole();
        app(OutboxDispatcher::class)->dispatchDue();
        $shipment = Shipment::query()->withoutGlobalScopes()->where('asn_id', $asn->id)->sole();
        $this->assertSame(['quoted', null], [$shipment->status, $shipment->selected_quote_id]);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
        $quote = TransportQuote::query()->where('shipment_id', $shipment->id)->where('quote_stage', 'final')->where('status', 'quoted')->sole();

        // The portal 预报入库 page lists the final quotes with client prices only and marks the client's earlier choice.
        $page = $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee(__('portal.asns.collection.reconfirm_title'))->assertSee(__('portal.asns.collection.your_choice'))->assertSee('$105.00')->assertSee('$75.00')
            ->assertSee(route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]), false)
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

    /** @return array{Client, User, Warehouse, OrderImport} a client whose 入库清单 asked us to collect and chose own fleet at $75.00 */
    private function clientChoseOwnFleet(): array
    {
        $client = $this->client(['default_markup_percent' => 0, 'invoice_mode' => 'per_job']);
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
