<?php

namespace Tests\Feature\Transport;

use App\Models\User;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use App\Support\Contracts\TransportOptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\Support\PortalCollectionFlow;
use Tests\Support\StubCarrierAdapter;
use Tests\TestCase;

/**
 * 重新报价 (CHANGE_REQUESTS #133, audit TMS-02): quotes die after 24 h and used to come only from the event consumers. A planner now
 * re-quotes a shipment that is not yet booked — an inbound collection (#124) included — the dead rows read 已过期 with their 有效至,
 * and the final stage re-runs the client-preference / variance rule, so the client's chosen plan is confirmed again within tolerance.
 */
class RequoteTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, PortalCollectionFlow, RefreshDatabase;

    private const OWN_FLEET_COST = 7500;

    private const DEMO_FREIGHT_COST = 8130;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-18 15:00:00'); // Friday afternoon
        $this->bindStubCarriers([
            'own_fleet' => ['code' => 'OWN-RQ', 'name' => 'Edward Own Fleet', 'level' => 'standard', 'cost' => self::OWN_FLEET_COST],
            'karrio' => ['code' => 'KAR-RQ', 'name' => 'Demo Freight', 'level' => 'express', 'cost' => self::DEMO_FREIGHT_COST],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_expired_quotes_read_as_expired_and_a_requote_replaces_them_with_fresh_selectable_ones(): void
    {
        $dispatcher = $this->staff('dispatcher');
        [, $shipment] = $this->collection();
        app(TransportOptionService::class)->quote($shipment->id, 'final');
        $this->assertSame('quoted', $shipment->fresh()->status);
        $old = TransportQuote::query()->where('shipment_id', $shipment->id)->orderBy('id')->get();
        $this->assertCount(2, $old);
        $ownFleet = $old->firstWhere('source', 'own_fleet');

        // Friday: live — 待选择, valid until Saturday 15:00, the confirm button and 重新报价 both offered.
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertSee(__('transport.quotes.expires_at'))
            ->assertSee('19/09 15:00')
            ->assertSee(route('transport.shipments.quotes.select', [$shipment, $ownFleet]))
            ->assertSee(route('transport.shipments.requote', $shipment))
            ->assertDontSee('>'.__('transport.quote_statuses.expired').'<', false);

        // Monday: dead — 已过期 instead of 待选择, no confirm button, selecting is still refused; 重新报价 is the way out.
        Carbon::setTestNow('2026-09-21 09:00:00');
        $page = $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertDontSee('>'.__('transport.quote_statuses.quoted').'<', false)
            ->assertDontSee(route('transport.shipments.quotes.select', [$shipment, $ownFleet]))
            ->assertSee(route('transport.shipments.requote', $shipment));
        $this->assertSame(2, substr_count($page->getContent(), '>'.__('transport.quote_statuses.expired').'<'));
        $this->actingAs($dispatcher)->post(route('transport.shipments.quotes.select', [$shipment, $ownFleet]))
            ->assertSessionHasErrors(['quote' => __('transport.selection.expired')]);

        StubCarrierAdapter::$costs['own_fleet'] = 8000; // the carrier prices differently on Monday
        $this->actingAs($dispatcher)->post(route('transport.shipments.requote', $shipment))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.quotes.requoted', ['count' => 2, 'stage' => __('transport.quote_stages.final')]));

        $this->assertSame(['expired', 'expired'], TransportQuote::query()->whereIn('id', $old->pluck('id'))->pluck('status')->all());
        $fresh = TransportQuote::query()->where('shipment_id', $shipment->id)->whereNotIn('id', $old->pluck('id'))->orderBy('id')->get();
        $this->assertCount(2, $fresh);
        $this->assertTrue($fresh->every(fn (TransportQuote $q): bool => $q->status === 'quoted' && $q->quote_stage === 'final' && $q->expires_at->eq(now()->addDay())));
        $this->assertSame(8000, (int) $fresh->firstWhere('source', 'own_fleet')->customer_price_cents);
        $this->assertSame(['quoted', null], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id]);
        $log = Activity::query()->where('log_name', 'shipment')->where('subject_id', $shipment->id)->latest('id')->firstOrFail();
        $this->assertSame(
            [__('transport.quotes.log.requoted'), $dispatcher->id, 'final', 2],
            [$log->description, (int) $log->causer_id, $log->properties['attributes']['stage'], $log->properties['attributes']['quotes']],
        );

        // The fresh rows carry the new validity and are selectable; confirming works again.
        $newOwnFleet = $fresh->firstWhere('source', 'own_fleet');
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertSee('22/09 09:00')
            ->assertSee(route('transport.shipments.quotes.select', [$shipment, $newOwnFleet]));
        $this->actingAs($dispatcher)->post(route('transport.shipments.quotes.select', [$shipment, $newOwnFleet]))->assertSessionHasNoErrors();
        $this->assertSame(['quote_confirmed', $newOwnFleet->id], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id]);
    }

    public function test_a_confirmed_collection_is_requoted_and_the_clients_choice_is_confirmed_again_within_tolerance(): void
    {
        $dispatcher = $this->staff('dispatcher');
        [$asn, $shipment, $user] = $this->collection(clientChose: true);
        app(TransportOptionService::class)->quote($shipment->id, 'final');
        $first = TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'selected')->sole();
        $this->assertSame(
            ['quote_confirmed', $first->id, 'client', $user->id, self::OWN_FLEET_COST],
            [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id, $first->selected_by, $first->selected_by_user_id, (int) $first->customer_price_cents],
        );
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
        // Confirmed but not booked: the page still offers 重新报价.
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()->assertSee(route('transport.shipments.requote', $shipment));

        // Monday, own fleet now $78.00 — within 10 % of the $75.00 the client chose: confirmed again in the client's name, nobody clicked.
        Carbon::setTestNow('2026-09-21 09:00:00');
        StubCarrierAdapter::$costs['own_fleet'] = 7800;
        $this->actingAs($dispatcher)->post(route('transport.shipments.requote', $shipment))->assertSessionHasNoErrors();
        $shipment->refresh();
        $second = TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'selected')->sole();
        $this->assertSame(
            ['quote_confirmed', $second->id, 'client', $user->id, 7800, 'requoted'],
            [$shipment->status, $shipment->selected_quote_id, $second->selected_by, $second->selected_by_user_id, (int) $second->customer_price_cents, $first->fresh()->status],
        );
        $this->assertSame(2, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
        $this->assertSame($asn->id, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->latest('id')->firstOrFail()->payload['asn_id']);

        // $105.00 is beyond the tolerance of what the client accepted: the shipment waits in `quoted` for a person, nothing confirmed.
        StubCarrierAdapter::$costs['own_fleet'] = 10500;
        $this->actingAs($dispatcher)->post(route('transport.shipments.requote', $shipment))->assertSessionHasNoErrors();
        $shipment->refresh();
        $this->assertSame(['quoted', null, null], [$shipment->status, $shipment->selected_quote_id, $shipment->carrier_id]);
        $this->assertSame(['requoted', 'requoted'], [$first->fresh()->status, $second->fresh()->status]);
        $live = TransportQuote::query()->where('shipment_id', $shipment->id)->where('status', 'quoted')->get();
        $this->assertSame([10500], $live->where('source', 'own_fleet')->pluck('customer_price_cents')->map(fn ($c): int => (int) $c)->values()->all());
        $this->assertSame(2, OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->count());
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertSee(route('transport.shipments.quotes.select', [$shipment, $live->firstWhere('source', 'own_fleet')]));
    }

    public function test_requote_is_for_planners_only_before_booking_and_serves_a_shipment_left_without_options(): void
    {
        $dispatcher = $this->staff('dispatcher');
        [, $shipment] = $this->collection();
        app(TransportOptionService::class)->quote($shipment->id, 'final');

        foreach (['transport_operator', 'finance', 'warehouse_supervisor', 'warehouse_operator'] as $role) {
            $this->actingAs($this->staff($role))->post(route('transport.shipments.requote', $shipment))->assertForbidden();
        }
        $this->actingAs($this->clientUser())->post(route('transport.shipments.requote', $shipment))->assertForbidden();
        $this->assertSame(2, TransportQuote::query()->where('shipment_id', $shipment->id)->count());
        $this->actingAs($this->staff('finance'))->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertDontSee(route('transport.shipments.requote', $shipment));

        // Booked: refused, the quotes untouched, no button.
        $quote = TransportQuote::query()->where('shipment_id', $shipment->id)->where('source', 'own_fleet')->sole();
        app(QuoteSelectionService::class)->select($shipment->fresh(), $quote, 'coordinator', $dispatcher->id);
        Shipment::query()->whereKey($shipment->id)->update(['status' => 'booked', 'booking_ref' => 'OWN-RQ-1']);
        $this->actingAs($dispatcher)->post(route('transport.shipments.requote', $shipment))
            ->assertSessionHasErrors(['requote' => __('transport.quotes.requote_invalid_status')]);
        $this->assertSame(['booked', $quote->id, 'selected'], [$shipment->fresh()->status, $shipment->fresh()->selected_quote_id, $quote->fresh()->status]);
        $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk()
            ->assertDontSee(route('transport.shipments.requote', $shipment));

        // A shipment left in `quoting` (no automatic option when its event came in) is refused in Chinese while there is still none…
        [, $bare] = $this->collection();
        $this->assertSame(['quoting', 0], [$bare->status, TransportQuote::query()->where('shipment_id', $bare->id)->count()]);
        CarrierService::query()->update(['active' => false]);
        $this->actingAs($dispatcher)->post(route('transport.shipments.requote', $bare))
            ->assertSessionHasErrors(['requote' => __('transport.quotes.requote_none')]);
        $this->assertSame('quoting', $bare->fresh()->status);
        $this->assertTrue(ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'manual_transport')->where('source_id', $bare->id)->exists());

        // …and gets its quotes as soon as a carrier answers.
        CarrierService::query()->update(['active' => true]);
        $this->actingAs($dispatcher)->post(route('transport.shipments.requote', $bare))->assertSessionHasNoErrors();
        $this->assertSame(['quoted', 2], [$bare->fresh()->status, TransportQuote::query()->where('shipment_id', $bare->id)->where('status', 'quoted')->count()]);
    }

    /**
     * An inbound collection shipment for a 预报单 (#124) at `quoting`, with — when the client chose — the own-fleet plan ticked on the
     * portal upload as `asns.collection_preference` (#125).
     *
     * @return array{Asn, Shipment, ?User}
     */
    private function collection(bool $clientChose = false): array
    {
        $client = $this->client();
        $user = $clientChose ? $this->clientUser($client) : null;
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $asn->update([
            'inbound_transport' => 'we_collect',
            'collection_address' => ['name' => 'Factory', 'phone' => '0400 000 001', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
            'collection_ready_date' => '2026-09-25',
            'collection_packages' => [
                ['package_type' => 'carton', 'qty' => 4, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
            ],
            'collection_status' => 'requested',
            'collection_version' => 1,
            'collection_requested_via' => $clientChose ? 'client' : 'staff',
            'collection_preference' => $clientChose ? [
                'carrier_id' => CarrierService::query()->where('source', 'own_fleet')->value('carrier_id'),
                'carrier_name' => 'Edward Own Fleet', 'source' => 'own_fleet', 'service_level' => 'standard',
                'customer_price_cents' => self::OWN_FLEET_COST, 'eta_days' => 1, 'is_recommended' => true, 'is_cheapest' => true, 'is_fastest' => true,
                'chosen_at' => now()->toIso8601String(), 'chosen_by' => $user->id,
            ] : null,
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.$asn->asn_no, 'job_id' => $asn->job_id, 'client_id' => $client->id, 'order_id' => null, 'asn_id' => $asn->id, 'asn_activity_version' => 1,
            'shipment_type' => 'inbound_collection', 'status' => 'quoting', 'service_level' => 'standard', 'tailgate_required' => false,
        ]);

        return [$asn->fresh(), $shipment, $user];
    }
}
