<?php

namespace Tests\Feature\Transport;

use App\Modules\Transport\Adapters\KarrioAdapter;
use App\Modules\Transport\Services\TransportOptionService;
use App\Support\Contracts\CarrierAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Karrio (open-source gateway) follows the frozen CarrierAdapter contract: rates → quote options, one-call purchase, cancel, label, tracker events. */
class KarrioAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.karrio.api_key' => 'key_test_123', 'services.karrio.base_url' => 'http://karrio.test:5002', 'services.karrio.carrier_ids' => 'demo-freight']);
    }

    public function test_adapter_is_registered_and_idle_without_a_key(): void
    {
        $adapters = collect(app()->tagged('transport.carrier-adapters'))->mapWithKeys(fn (CarrierAdapter $a) => [$a->source() => $a]);
        $this->assertArrayHasKey('karrio', $adapters->all());
        $this->assertSame(['quote' => true, 'book' => true, 'cancel' => true, 'label' => true, 'tracking' => 'poll', 'pod' => 'manual'], $adapters['karrio']->capabilities());

        config(['services.karrio.api_key' => '']);
        Http::fake();
        $this->assertSame([], app(KarrioAdapter::class)->quote($this->request()));
        $this->assertSame('request_failed', app(KarrioAdapter::class)->book($this->request(), 'demo-freight::standard')['status']);
        Http::assertNothingSent();
    }

    public function test_rates_become_quote_options_with_cents_and_service_levels(): void
    {
        Http::fake(['http://karrio.test:5002/v1/proxy/rates' => Http::response(['rates' => [
            ['id' => 'rat_1', 'carrier_name' => 'generic', 'carrier_id' => 'demo-freight', 'service' => 'road_standard', 'total_charge' => 86.5, 'currency' => 'AUD', 'transit_days' => 3, 'meta' => ['service_name' => 'Road Freight'], 'test_mode' => true],
            ['id' => 'rat_2', 'carrier_name' => 'generic', 'carrier_id' => 'demo-freight', 'service' => 'road_express', 'total_charge' => 129, 'currency' => 'AUD', 'transit_days' => 1, 'test_mode' => true],
            ['id' => 'rat_3', 'carrier_name' => 'generic', 'carrier_id' => 'demo-freight', 'service' => 'free', 'total_charge' => 0, 'currency' => 'AUD'],
        ], 'messages' => []])]);

        $options = app(KarrioAdapter::class)->quote($this->request());

        $this->assertCount(2, $options); // the zero-priced rate is dropped (never quote $0)
        $this->assertSame(['demo-freight::road_standard', 8650, 'standard', 3, ['2026-09-08']], [$options[0]['service_code'], $options[0]['cost_cents'], $options[0]['service_level'], $options[0]['eta_days'], $options[0]['pickup_dates']]);
        $this->assertSame(['demo-freight::road_express', 12900, 'express'], [$options[1]['service_code'], $options[1]['cost_cents'], $options[1]['service_level']]);
        $this->assertSame('rat_1', $options[0]['raw']['booking_id']);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Token key_test_123')
                && $body['shipper']['postal_code'] === '3175' && $body['recipient']['country_code'] === 'AU'
                && count($body['parcels']) === 2 // qty 2 → two parcels
                && $body['parcels'][0] === ['weight' => 12.5, 'weight_unit' => 'KG', 'length' => 40.0, 'width' => 30.0, 'height' => 25.0, 'dimension_unit' => 'CM', 'packaging_type' => 'your_packaging', 'description' => 'Carton']
                && $body['carrier_ids'] === ['demo-freight'] && $body['options']['declared_value'] === 100.0 && $body['options']['currency'] === 'AUD';
        });
    }

    public function test_booking_buys_the_label_in_one_call_or_purchases_the_matching_rate(): void
    {
        Http::fake([
            'http://karrio.test:5002/v1/shipments' => Http::sequence()
                ->push(['id' => 'shp_1', 'status' => 'purchased', 'tracking_number' => 'DEMO0001', 'label_url' => '/v1/documents/doc_1.pdf', 'carrier_name' => 'generic', 'tracker_id' => 'trk_1'])
                ->push(['id' => 'shp_2', 'status' => 'draft', 'rates' => [['id' => 'rat_9', 'carrier_id' => 'demo-freight', 'service' => 'road_standard', 'total_charge' => 80]]]),
            'http://karrio.test:5002/v1/shipments/shp_2/purchase' => Http::response(['id' => 'shp_2', 'status' => 'purchased', 'tracking_number' => 'DEMO0002', 'label_url' => 'http://karrio.test:5002/v1/documents/doc_2.pdf']),
            'http://karrio.test:5002/v1/shipments/shp_1/cancel' => Http::response(['id' => 'shp_1', 'status' => 'cancelled']),
        ]);
        $adapter = app(KarrioAdapter::class);

        $first = $adapter->book($this->request(), 'demo-freight::road_standard', ['quote_ref' => 'rat_1', 'pickup_date' => '2026-09-08']);
        $this->assertSame(['shp_1', 'DEMO0001', '/v1/documents/doc_1.pdf', 'booked'], [$first['booking_ref'], $first['tracking_number'], $first['label_path'], $first['status']]);

        $second = $adapter->book($this->request(), 'demo-freight::road_standard');
        $this->assertSame(['shp_2', 'DEMO0002', 'booked'], [$second['booking_ref'], $second['tracking_number'], $second['status']]);

        $this->assertTrue($adapter->cancel('shp_1'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/shipments') && data_get($r->data(), 'service') === 'road_standard' && data_get($r->data(), 'carrier_ids') === ['demo-freight'] && data_get($r->data(), 'label_type') === 'PDF' && data_get($r->data(), 'metadata.erp_quote_ref') === 'rat_1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/shipments/shp_2/purchase') && data_get($r->data(), 'selected_rate_id') === 'rat_9');
    }

    public function test_label_and_tracking_follow_the_shipment_links(): void
    {
        Http::fake([
            'http://karrio.test:5002/v1/shipments/shp_1' => Http::response(['id' => 'shp_1', 'status' => 'purchased', 'tracking_number' => 'DEMO0001', 'label_url' => '/v1/documents/doc_1.pdf', 'carrier_name' => 'generic', 'tracker_id' => 'trk_1']),
            'http://karrio.test:5002/v1/documents/doc_1.pdf' => Http::response('%PDF-1.4 demo label', 200, ['Content-Type' => 'application/pdf']),
            'http://karrio.test:5002/v1/trackers/trk_1' => Http::response(['id' => 'trk_1', 'status' => 'in_transit', 'delivered' => false, 'events' => [
                ['date' => '2026-09-08', 'time' => '09:15 AM', 'description' => 'Picked up', 'location' => 'Dandenong South VIC', 'code' => 'PU'],
                ['date' => '2026-09-08', 'time' => '06:40 PM', 'description' => 'In transit', 'location' => 'Melbourne depot', 'code' => null],
            ]]),
        ]);
        $adapter = app(KarrioAdapter::class);

        $this->assertStringStartsWith('%PDF', (string) $adapter->label('shp_1'));
        $events = $adapter->tracking('shp_1');
        $this->assertCount(2, $events);
        $this->assertSame(['PU', 'Picked up', 'Dandenong South VIC'], [$events[0]['status'], $events[0]['description'], $events[0]['location']]);
        $this->assertSame('in_transit', $events[1]['status']); // no event code → tracker status
        $this->assertStringStartsWith('2026-09-08T09:15:00', (string) $events[0]['occurred_at']);
    }

    public function test_transport_option_service_can_see_the_karrio_source(): void
    {
        $service = app(TransportOptionService::class);
        $adapters = (fn () => $this->adapters)->call($service);
        $this->assertInstanceOf(KarrioAdapter::class, $adapters['karrio']);
    }

    /** @return array<string, mixed> */
    private function request(): array
    {
        return [
            'client_id' => 1,
            'description' => 'ORD-TEST-0001',
            'sender' => ['name' => 'Sender', 'company_name' => 'Edward Logistics', 'address' => '1 Start St', 'suburb' => 'Dandenong South', 'state' => 'VIC', 'postcode' => '3175', 'type' => 'business'],
            'receiver' => ['name' => 'Receiver', 'address' => '2 End St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
            'items' => [['description' => 'Carton', 'qty' => 2, 'weight_kg' => 12.5, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
            'declared_value_cents' => 10000,
            'tailgate_pickup' => false,
            'tailgate_delivery' => true,
            'requested_date' => '2026-09-08',
        ];
    }
}
