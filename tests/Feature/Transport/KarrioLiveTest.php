<?php

namespace Tests\Feature\Transport;

use App\Modules\Transport\Adapters\KarrioAdapter;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Talks to a real local Karrio (docker/karrio) — skipped unless KARRIO_LIVE=1 and KARRIO_API_KEY are set, so CI never
 * depends on it. Run: KARRIO_LIVE=1 php artisan test --filter KarrioLiveTest
 */
#[Group('live')]
class KarrioLiveTest extends TestCase
{
    public function test_quote_book_label_and_cancel_against_the_local_instance(): void
    {
        if (env('KARRIO_LIVE') !== '1' || blank(config('services.karrio.api_key'))) {
            $this->markTestSkipped('Set KARRIO_LIVE=1 and KARRIO_API_KEY to run against a local Karrio.');
        }
        Http::preventingStrayRequests(false);
        $adapter = app(KarrioAdapter::class);
        $request = [
            'client_id' => 1, 'description' => 'ERP live check',
            'sender' => ['name' => 'Edward DC', 'company_name' => 'Edward Logistics', 'phone' => '0300000000', 'email' => 'ops@example.com', 'address' => '1 Depot Rd', 'suburb' => 'Dandenong South', 'state' => 'VIC', 'postcode' => '3175', 'type' => 'business'],
            'receiver' => ['name' => 'Receiver', 'company_name' => 'Receiver Pty Ltd', 'phone' => '0400000000', 'email' => 'rx@example.com', 'address' => '2 End St', 'suburb' => 'Sydney', 'state' => 'NSW', 'postcode' => '2000', 'type' => 'business'],
            'items' => [['description' => 'Carton', 'qty' => 1, 'weight_kg' => 10, 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250]],
            'declared_value_cents' => 10000, 'tailgate_pickup' => false, 'tailgate_delivery' => false, 'requested_date' => now()->addDay()->toDateString(),
        ];

        $options = $adapter->quote($request);
        $this->assertNotEmpty($options, 'Karrio returned no rates — add a carrier connection (Dashboard → Carriers) with a rate sheet covering VIC → NSW.');

        $booking = $adapter->book($request, $options[0]['service_code'], ['quote_ref' => $options[0]['raw']['booking_id']]);
        $this->assertSame('booked', $booking['status'], json_encode($booking['raw']));
        $this->assertNotEmpty($booking['tracking_number']);
        $this->assertStringStartsWith('%PDF', (string) $adapter->label($booking['booking_ref']));
        $this->assertTrue($adapter->cancel($booking['booking_ref']));
    }
}
