<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\ConsignmentNotePdf;
use App\Modules\Transport\Services\PackageManifest;
use App\Modules\Transport\Services\QuoteSelectionService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Modules\Transport\Support\TransportEnums;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * Transport side of 我方上门提货 (CHANGE_REQUESTS #124, C as integrator in the X2 zone): an `inbound_collection` shipment has no order —
 * the quote request is built from the 预报单 (sender = pickup, receiver = our warehouse, zone = pickup postcode, tailgate at
 * pickup), the confirmed-quote payload tolerates the missing order, the consignment note renders in English without one, and
 * the shipment page links the ASN where an order shipment links its order.
 */
class InboundCollectionShipmentTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private const CJK = '/[\x{4e00}-\x{9fff}]/u';

    public function test_the_quote_request_of_a_collection_comes_from_the_asn(): void
    {
        [$asn, $shipment] = $this->collection();

        $request = app(ShipmentQuoteRequestFactory::class)->build($shipment, 'final');
        $this->assertNotNull($request);
        $this->assertSame(['Factory', '9 Supplier Rd', 'Laverton', 'VIC', '3028', 'residential'], [$request['sender']['name'], $request['sender']['address'], $request['sender']['suburb'], $request['sender']['state'], $request['sender']['postcode'], $request['sender']['type']]);
        $this->assertSame(['MEL DC', '1 Depot Road', 'Dandenong South', 'VIC', '3175', 'business'], [$request['receiver']['name'], $request['receiver']['address'], $request['receiver']['suburb'], $request['receiver']['state'], $request['receiver']['postcode'], $request['receiver']['type']]);
        $this->assertSame([['pallet', 1, 300.0, 1200, 1200, 1400], ['carton', 2, 10.0, 400, 300, 250]], array_map(fn (array $i) => [$i['description'], $i['qty'], (float) $i['weight_kg'], $i['length_mm'], $i['width_mm'], $i['height_mm']], $request['items']));
        $this->assertSame(['3028', true, false, $asn->collection_ready_date->toDateString(), $shipment->shipment_no, 0], [$request['zone'], $request['tailgate_pickup'], $request['tailgate_delivery'], $request['requested_date'], $request['description'], $request['declared_value_cents']]);
        $this->assertEquals($request, app(ShipmentQuoteRequestFactory::class)->build($shipment, 'preliminary'), 'the declared list is final at both stages');

        // Without declared packages the goods lines (cartons × line weight / dims) are the parcels; a line without dims is left out.
        app(AsnService::class)->addLines($asn, [
            ['description' => 'Speakers', 'expected_cartons' => 4, 'weight_kg' => 40, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
            ['description' => 'No dims', 'expected_cartons' => 2, 'weight_kg' => 5],
        ]);
        $asn->update(['collection_packages' => []]);
        $items = app(ShipmentQuoteRequestFactory::class)->build($shipment->fresh(), 'final')['items'];
        $this->assertSame([['Speakers', 4, 10.0]], array_map(fn (array $i) => [$i['description'], $i['qty'], (float) $i['weight_kg']], $items));

        // The manifest for the consignment note / own-fleet labels: one row per declared piece, barcoded by the shipment.
        $asn->update(['collection_packages' => [['package_type' => 'pallet', 'qty' => 2, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400]]]);
        $rows = app(PackageManifest::class)->forShipment($shipment->fresh());
        $this->assertSame([[$shipment->shipment_no.'-1', 'pallet', '300.000'], [$shipment->shipment_no.'-2', 'pallet', '300.000']], array_map(fn (array $r) => [$r['carton_label'], $r['package_type'], $r['weight_kg']], $rows));
    }

    public function test_the_confirmed_quote_payload_and_the_pdf_and_page_work_without_an_order(): void
    {
        [$asn, $shipment] = $this->collection();
        $this->assertTrue(TransportEnums::isDelivery('inbound_collection'));
        $this->assertSame(TransportEnums::OUTBOUND_STATUSES, TransportEnums::shipmentStatuses('inbound_collection'));

        $quote = $this->finalQuote($shipment);
        $shipment->update(['status' => 'quoted']);
        $dispatcher = $this->staff('dispatcher');
        app(QuoteSelectionService::class)->select($shipment->fresh(), $quote, 'coordinator', $dispatcher->id);
        $event = OutboxEvent::query()->where('event_name', 'shipment.quote_confirmed')->sole();
        $payload = $event->payload;
        $this->assertSame([$asn->id, null, null, 'inbound_collection', 'final', 12000, '3028', true], [$payload['asn_id'], $payload['order_id'], $payload['order_type'], $payload['shipment_type'], $payload['quote_stage'], $payload['customer_price_cents'], $payload['zone'], $payload['tailgate_required']]);
        $this->assertSame([3, 320.0, round(1.2 * 1.2 * 1.4 + 2 * 0.4 * 0.3 * 0.25, 6)], [$payload['packages']['count'], (float) $payload['packages']['total_weight_kg'], (float) $payload['packages']['total_cbm']]);
        foreach (['lines', 'pallet_count', 'carton_count', 'label_count', 'is_urgent'] as $key) {
            $this->assertArrayNotHasKey($key, $payload, "a collection carries no outbound handling key ($key)");
        }
        $this->assertSame($asn->job_id, $event->job_id);

        // The consignment note (English PDF) names the ASN, the pickup party and our warehouse — no order, no Chinese, no raw key.
        $html = view('transport::shipments.consignment-note', $this->noteData($shipment->fresh()))->render();
        $this->assertStringContainsString($asn->asn_no, $html);
        $this->assertStringContainsString(__('pdf.consignment_note.asn'), $html);
        $this->assertStringContainsString(__('pdf.consignment_note.collect_from'), $html);
        $this->assertStringContainsString('9 Supplier Rd', $html);
        $this->assertStringContainsString('1 Depot Road', $html);
        $this->assertStringNotContainsString(__('pdf.consignment_note.order'), $html);
        $this->assertStringNotContainsString('pdf.', $html);
        $this->assertDoesNotMatchRegularExpression(self::CJK, $html);
        $this->actingAs($dispatcher)->get(route('transport.shipments.consignment-note', $shipment))->assertOk()->assertHeader('content-type', 'application/pdf');

        // The shipment page and the list: ASN link instead of an order, pickup → warehouse header, no margin link, no raw key.
        $page = $this->actingAs($dispatcher)->get(route('transport.shipments.show', $shipment))->assertOk();
        $page->assertSee(__('transport.shipment_types.inbound_collection'))->assertSee(__('transport.shipments.collection_header'))->assertSee(route('warehouse.asns.show', $asn->id))->assertSee($asn->asn_no)
            ->assertSee('9 Supplier Rd')->assertSee('MEL DC')->assertSee(__('transport.quotes.client_preference_collection'))
            ->assertDontSee(__('transport.shipments.margin_link'))->assertDontSee('transport.shipments.')->assertDontSee('/margin');
        $this->actingAs($dispatcher)->get(route('transport.index'))->assertOk()->assertSee(__('transport.shipment_types.inbound_collection'))->assertSee($asn->asn_no);
    }

    /** @return array{Asn, Shipment} a we_collect ASN (residential pickup, one 300 kg pallet + two 10 kg cartons) and its quoting collection shipment */
    private function collection(): array
    {
        $client = $this->client();
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        $asn->update([
            'inbound_transport' => 'we_collect',
            'collection_address' => ['name' => 'Factory', 'phone' => '0400 000 001', 'address' => '9 Supplier Rd', 'suburb' => 'Laverton', 'state' => 'VIC', 'postcode' => '3028', 'type' => 'residential'],
            'collection_ready_date' => today()->addDays(2)->toDateString(),
            'collection_packages' => [
                ['package_type' => 'pallet', 'qty' => 1, 'weight_kg' => 300, 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400],
                ['package_type' => 'carton', 'qty' => 2, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250],
            ],
            'collection_status' => 'requested', 'collection_version' => 1,
        ]);
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-'.$asn->asn_no, 'job_id' => $asn->job_id, 'client_id' => $client->id, 'order_id' => null, 'asn_id' => $asn->id, 'asn_activity_version' => 1,
            'shipment_type' => 'inbound_collection', 'status' => 'quoting', 'service_level' => 'standard', 'tailgate_required' => true,
        ]);

        return [$asn->fresh(), $shipment];
    }

    private function finalQuote(Shipment $shipment): TransportQuote
    {
        $carrier = Carrier::query()->create(['code' => 'OWN-'.str()->upper(str()->random(6)), 'name' => 'ERP Own Fleet', 'status' => 'active']);
        $request = app(ShipmentQuoteRequestFactory::class)->build($shipment, 'final');

        return TransportQuote::query()->create([
            'shipment_id' => $shipment->id, 'carrier_id' => $carrier->id, 'source' => 'own_fleet', 'service_level' => 'standard',
            'cost_cents' => 10000, 'customer_price_cents' => 12000, 'markup_percent' => 20, 'eta_days' => 1, 'quote_stage' => 'final', 'status' => 'quoted',
            'quoted_at' => now(), 'expires_at' => now()->addDay(),
            'raw_response' => ['pricing_mode' => 'fixed', '_quote_request' => ['zone' => $request['zone'], 'items' => $request['items'], 'receiver' => $request['receiver'], 'sender' => $request['sender']]],
        ]);
    }

    /** The view data ConsignmentNotePdf hands to the template, rendered as HTML for assertions. */
    private function noteData(Shipment $shipment): array
    {
        $pdf = app(ConsignmentNotePdf::class)->render($shipment, app(PackageManifest::class)->forShipment($shipment));
        $this->assertStringStartsWith('%PDF-', $pdf->output());
        $shipment->loadMissing(['client', 'job', 'carrier', 'selectedQuote']);
        $raw = $shipment->selectedQuote?->raw_response ?? [];
        $rows = collect(app(PackageManifest::class)->forShipment($shipment));

        return [
            'shipment' => $shipment, 'asnNo' => Asn::query()->withoutGlobalScopes()->whereKey($shipment->asn_id)->value('asn_no'),
            'parties' => ['sender' => (array) data_get($raw, '_quote_request.sender', []), 'receiver' => (array) data_get($raw, '_quote_request.receiver', [])],
            'packages' => $rows, 'packageCount' => $rows->count(), 'totalWeightKg' => $rows->sum(fn (array $p) => (float) $p['weight_kg']), 'generatedAt' => now(),
        ];
    }
}
