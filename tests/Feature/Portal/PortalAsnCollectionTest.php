<?php

namespace Tests\Feature\Portal;

use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 预报入库 in the portal shows the 到仓方式 card for a collection (CHANGE_REQUESTS #124): pickup address, ready date, progress, the
 * confirmed plan and the CLIENT price — never a carrier cost or margin figure; another client never sees it; a 客户自送 ASN has no card.
 */
class PortalAsnCollectionTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_the_client_sees_its_collection_with_status_and_client_price_but_never_a_cost(): void
    {
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck', 'reference' => 'PO-COL']);
        $asn->update([
            'inbound_transport' => 'we_collect',
            'collection_address' => ['name' => 'Factory', 'phone' => '0400 000 001', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'business'],
            'collection_ready_date' => '2026-10-02',
            'collection_packages' => [['package_type' => 'pallet', 'qty' => 2, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400]],
            'collection_notes' => 'Dock 3',
            'collection_status' => 'requested', 'collection_version' => 1,
        ]);

        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee(__('portal.asns.collection.modes.we_collect'))->assertSee(__('portal.asns.collection.statuses.requested'))
            ->assertSee('9 Supplier Rd')->assertSee('2026-10-02')->assertSee('2 × '.__('portal.asns.collection.package_types.pallet'))->assertSee('Dock 3')
            ->assertSee(__('portal.asns.collection.plan_pending'));

        // Transport confirmed a plan: the consumer stores the client price only (the carrier cost of $60.00 never reaches the ASN).
        app(AsnService::class)->recordCollectionProgress($asn, 'confirmed', 4242, [
            'shipment_no' => 'SHP-'.$asn->asn_no, 'source' => 'own_fleet', 'carrier_id' => null, 'carrier_name' => 'Edward Own Fleet', 'service_level' => 'standard',
            'customer_price_cents' => 7500, 'eta_days' => 1, 'confirmed_by_type' => 'coordinator', 'confirmed_at' => now()->toIso8601String(),
        ]);
        $response = $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()
            ->assertSee(__('portal.asns.collection.statuses.confirmed'))->assertSee('Edward Own Fleet')->assertSee('$75.00')->assertSee(__('portal.asns.collection.fields.customer_price'))
            ->assertDontSee('$60.00')->assertDontSee('cost_cents')->assertDontSee('markup')->assertDontSee('margin_cents')->assertDontSee('transport/4242')->assertDontSee('portal.asns.collection.');
        $visible = preg_replace(['/<script.*?<\/script>/s', '/<style.*?<\/style>/s', '/ style="[^"]*"/'], '', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\b(cost|margin)\b/i', $visible, 'no cost / margin word on the client page');
        $this->assertNull($asn->fresh()->collection_plan['carrier_id'] ?? null);
        $this->assertArrayNotHasKey('cost_cents', $asn->fresh()->collection_plan);

        // Another client never sees it; a 客户自送 ASN shows no collection card.
        $this->actingAs($this->clientUser($this->client()))->get(route('portal.asns.show', $asn))->assertNotFound();
        $plain = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel']);
        $this->actingAs($user)->get(route('portal.asns.show', $plain))->assertOk()->assertDontSee(__('portal.asns.collection.modes.we_collect'))->assertDontSee(__('portal.asns.collection.title'));
    }
}
