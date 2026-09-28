<?php

namespace Tests\Feature\Transport;

use App\Modules\Transport\Adapters\ExampleHttpCarrierAdapter;
use App\Support\Contracts\CarrierAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\CarrierAdapterTestCase;

/**
 * CHANGE_REQUESTS #163: the template adapter behind docs/carrier-integration.md passes the contract test against a faked carrier and
 * shows the whole cycle a real adapter's test should cover. It is not registered, so production never sees the `example` source.
 */
class ExampleHttpCarrierAdapterTest extends CarrierAdapterTestCase
{
    private const BASE = 'https://api.example-carrier.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.example_carrier.base_url' => self::BASE, 'services.example_carrier.api_key' => 'key_test_example']);
    }

    protected function adapter(): CarrierAdapter
    {
        return app(ExampleHttpCarrierAdapter::class);
    }

    protected function fakeCarrierForQuote(): void
    {
        Http::fake([self::BASE.'/v1/quotes' => Http::response(['quotes' => [
            ['id' => 'q_1', 'service' => 'road_standard', 'name' => 'Road Standard', 'price' => 86.5, 'transit_days' => 3, 'pickup_dates' => ['2026-09-08', '2026-09-09']],
            ['id' => 'q_2', 'service' => 'road_express', 'name' => 'Road Express', 'price' => 129, 'transit_days' => 1],
            ['id' => 'q_3', 'service' => 'free_promo', 'name' => 'Free', 'price' => 0],
        ]])]);
    }

    protected function fakeCarrierForBooking(): void
    {
        Http::fake([
            self::BASE.'/v1/quotes' => Http::response(['quotes' => [['id' => 'q_1', 'service' => 'road_standard', 'name' => 'Road Standard', 'price' => 86.5, 'transit_days' => 3]]]),
            self::BASE.'/v1/consignments' => Http::response(['consignment_no' => 'EX123456', 'tracking_number' => 'TRK-EX123456', 'status' => 'booked', 'label_url' => self::BASE.'/v1/consignments/EX123456/label']),
        ]);
    }

    protected function fakeCarrierForLabel(): void
    {
        Http::fake([self::BASE.'/v1/consignments/REF-1/label' => Http::response("%PDF-1.4\n%fake", 200, ['Content-Type' => 'application/pdf'])]);
    }

    protected function fakeCarrierForTracking(): void
    {
        Http::fake([self::BASE.'/v1/consignments/REF-1/events' => Http::response(['events' => [
            ['status' => 'Picked up', 'description' => 'Collected from sender', 'location' => 'Dandenong South VIC', 'occurred_at' => '2026-09-08T09:15:00+10:00'],
            ['status' => 'In transit', 'location' => 'Sydney NSW', 'occurred_at' => '2026-09-09T06:00:00+10:00'],
            ['status' => '', 'description' => 'dropped: no status'],
        ]])]);
    }

    protected function fakeCarrierForCancel(): void
    {
        Http::fake([self::BASE.'/v1/consignments/REF-1' => Http::response('', 204)]);
    }

    public function test_quotes_are_converted_to_cents_and_service_levels_and_carry_the_quote_id(): void
    {
        $this->fakeCarrierForQuote();
        $options = $this->adapter()->quote($this->request());

        $this->assertCount(2, $options, 'the $0 quote is dropped');
        $this->assertSame(['road_standard', 8650, 'standard', 3, ['2026-09-08', '2026-09-09'], 'q_1'], [$options[0]['service_code'], $options[0]['cost_cents'], $options[0]['service_level'], $options[0]['eta_days'], $options[0]['pickup_dates'], $options[0]['raw']['booking_id']]);
        $this->assertSame(['road_express', 12900, 'express', 1, []], [$options[1]['service_code'], $options[1]['cost_cents'], $options[1]['service_level'], $options[1]['eta_days'], $options[1]['pickup_dates']]);
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer key_test_example')
                && $body['sender']['postcode'] === '3175' && $body['receiver']['residential'] === false
                && count($body['parcels']) === 3 // qty 2 + qty 1 → three parcels
                && $body['parcels'][0] === ['weight_kg' => 12.5, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 25, 'description' => 'carton']
                && $body['declared_value'] === 100.0 && $body['tailgate_delivery'] === true && $body['ready_date'] === '2026-09-08';
        });
    }

    public function test_booking_posts_the_chosen_service_and_quote_and_returns_the_consignment(): void
    {
        $this->fakeCarrierForBooking();
        $result = $this->adapter()->book($this->request(), 'road_standard', ['quote_ref' => 'q_1', 'pickup_date' => '2026-09-08']);

        $this->assertSame(['EX123456', 'TRK-EX123456', 'booked'], [$result['booking_ref'], $result['tracking_number'], $result['status']]);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v1/consignments')
            && $request['service'] === 'road_standard' && $request['quote_id'] === 'q_1' && $request['pickup_date'] === '2026-09-08' && $request['reference'] === 'SHP-TEST-0001');
    }

    public function test_a_carrier_error_is_a_refused_booking_and_a_missing_key_keeps_the_adapter_silent(): void
    {
        Http::fake([self::BASE.'/v1/consignments' => Http::response(['message' => 'postcode not serviced'], 422)]);
        $result = $this->adapter()->book($this->request(), 'road_standard');
        $this->assertSame('request_failed', $result['status']);
        $this->assertSame('postcode not serviced', $result['raw']['response']['message']);

        config(['services.example_carrier.api_key' => '']);
        Http::fake();
        $this->assertSame([], $this->adapter()->quote($this->request()));
        $this->assertSame('request_failed', $this->adapter()->book($this->request(), 'road_standard')['status']);
        Http::assertNothingSent();
    }

    public function test_the_template_is_not_registered_as_a_production_adapter(): void
    {
        $sources = collect(app()->tagged('transport.carrier-adapters'))->map(fn (CarrierAdapter $a) => $a->source())->all();
        $this->assertNotContains('example', $sources);
        $this->assertSame(['manual', 'own_fleet', 'transdirect', 'karrio'], $sources, 'the registered sources, in tag order');
    }
}
