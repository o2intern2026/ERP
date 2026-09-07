<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Models\Pod;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B7DriverPodTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-07 09:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_pods_table_matches_the_transport_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('pods', [
            'shipment_id', 'delivered_at', 'recipient_name', 'signature_document_id',
            'photo_document_ids', 'pod_document_id', 'failure_reason', 'captured_by',
        ]));
    }

    public function test_driver_sees_only_todays_assigned_stops_and_prominent_tailgate_warning(): void
    {
        $driver = $this->staff('transport_operator', ['name' => 'Driver B7']);
        $otherDriver = $this->staff('transport_operator');
        $visibleStop = $this->stop($driver->id, shipmentAttributes: ['tailgate_required' => true]);
        $otherStop = $this->stop($otherDriver->id);
        $tomorrowStop = $this->stop($driver->id, '2026-09-08');

        $this->actingAs($driver)->get(route('transport.driver'))
            ->assertOk()
            ->assertSee($visibleStop->shipment->shipment_no)
            ->assertSee(__('transport.driver.tailgate_required'))
            ->assertSee(__('transport.driver.capture_delivery'))
            ->assertSee(__('transport.driver.report_failure'))
            ->assertDontSee($otherStop->shipment->shipment_no)
            ->assertDontSee($tomorrowStop->shipment->shipment_no);

        $this->actingAs($this->staff('finance'))->get(route('transport.driver'))->assertForbidden();
    }

    public function test_driver_signature_and_photos_create_a_pod_pdf_and_exact_event(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $stop = $this->stop($driver->id);
        $response = $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $stop), [
            'recipient_name' => 'Receiving Person',
            'signature_data' => $this->signatureData(),
            'photos' => [UploadedFile::fake()->image('delivered.jpg', 120, 80)],
        ]);

        $response->assertRedirect(route('transport.driver'))
            ->assertSessionHas('status', __('transport.driver.delivered'));

        $pod = Pod::query()->sole();
        $this->assertSame($stop->shipment_id, $pod->shipment_id);
        $this->assertSame('Receiving Person', $pod->recipient_name);
        $this->assertSame($driver->id, $pod->captured_by);
        $this->assertSame('2026-09-07T09:30:00+10:00', $pod->delivered_at->toIso8601String());
        $this->assertNull($pod->failure_reason);
        $this->assertCount(1, $pod->photo_document_ids);

        $signature = Document::query()->findOrFail($pod->signature_document_id);
        $photo = Document::query()->findOrFail($pod->photo_document_ids[0]);
        $pdf = Document::query()->findOrFail($pod->pod_document_id);
        $this->assertSame('photo', $signature->type);
        $this->assertSame('photo', $photo->type);
        $this->assertSame('pod', $pdf->type);
        $this->assertSame('shipment', $pdf->related_type);
        $this->assertSame($stop->shipment_id, $pdf->related_id);
        $this->assertTrue($pdf->client_visible);
        $this->assertSame($driver->id, $pdf->uploaded_by);
        Storage::disk('local')->assertExists($signature->storage_path);
        Storage::disk('local')->assertExists($photo->storage_path);
        Storage::disk('local')->assertExists($pdf->storage_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($pdf->storage_path));

        $shipment = $stop->shipment;
        $event = OutboxEvent::query()->where('event_name', 'delivery.pod_captured')->sole();
        $this->assertEquals([
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'fulfilment_id' => $shipment->fulfilment_id,
            'delivered_at' => '2026-09-07T09:30:00+10:00',
            'recipient_name' => 'Receiving Person',
            'pod_document_id' => $pdf->id,
            'photo_document_ids' => [$photo->id],
            'captured_by_type' => 'driver',
            'captured_by' => $driver->id,
        ], $event->payload);
        $this->assertSame($shipment->shipment_no, $event->correlation_id);
    }

    public function test_delivery_requires_a_signature_and_at_least_one_photo(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $stop = $this->stop($driver->id);

        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $stop), [
            'recipient_name' => 'Receiving Person',
        ])->assertSessionHasErrors(['signature_data', 'photos']);

        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $stop), [
            'recipient_name' => 'Receiving Person',
            'signature_data' => 'data:image/png;base64,not-a-png',
            'photos' => [UploadedFile::fake()->image('delivered.jpg')],
        ])->assertSessionHasErrors('pod');
        $this->assertDatabaseCount('pods', 0);
        $this->assertDatabaseCount('documents', 0);
        Storage::disk('local')->assertDirectoryEmpty('transport');
    }

    public function test_driver_reports_each_failed_attempt_with_the_exact_event(): void
    {
        $driver = $this->staff('transport_operator');
        $stop = $this->stop($driver->id);

        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop), [
            'failure_reason' => 'recipient_unavailable',
        ])->assertRedirect(route('transport.driver'))
            ->assertSessionHas('status', __('transport.driver.failed'));

        $pod = Pod::query()->sole();
        $this->assertSame('recipient_unavailable', $pod->failure_reason);
        $this->assertNull($pod->delivered_at);
        $this->assertNull($pod->pod_document_id);
        $this->assertSame($driver->id, $pod->captured_by);

        $shipment = $stop->shipment;
        $event = OutboxEvent::query()->where('event_name', 'delivery.failed')->sole();
        $this->assertEquals([
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'failed_at' => '2026-09-07T09:30:00+10:00',
            'failure_reason' => 'recipient_unavailable',
            'attempt_no' => 1,
            'reported_by_type' => 'driver',
        ], $event->payload);

        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop), [
            'failure_reason' => 'access_blocked',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, OutboxEvent::query()->where('event_name', 'delivery.failed')->count());
        $this->assertSame(2, OutboxEvent::query()->where('event_name', 'delivery.failed')->latest('id')->first()->payload['attempt_no']);
    }

    public function test_a_driver_cannot_submit_another_drivers_stop_or_repeat_a_delivery(): void
    {
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $otherDriver = $this->staff('transport_operator');
        $stop = $this->stop($driver->id);
        $payload = [
            'recipient_name' => 'Receiving Person',
            'signature_data' => $this->signatureData(),
            'photos' => [UploadedFile::fake()->image('delivered.jpg')],
        ];

        $this->actingAs($otherDriver)
            ->post(route('transport.driver.stops.deliver', $stop), $payload)
            ->assertForbidden();
        $this->assertDatabaseCount('pods', 0);

        $this->actingAs($driver)
            ->post(route('transport.driver.stops.deliver', $stop), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($driver)
            ->post(route('transport.driver.stops.deliver', $stop), [
                'recipient_name' => 'Second Person',
                'signature_data' => $this->signatureData(),
                'photos' => [UploadedFile::fake()->image('second.jpg')],
            ])->assertSessionHasErrors('pod');
        $this->assertDatabaseCount('pods', 1);
        $this->assertSame(1, OutboxEvent::query()->where('event_name', 'delivery.pod_captured')->count());
    }

    private function stop(int $driverId, string $runDate = '2026-09-07', array $shipmentAttributes = []): RunStop
    {
        $shipment = $this->shipment($shipmentAttributes);
        $run = app(DeliveryRunService::class)->create($runDate, $driverId, 'VAN-B7');

        return app(DeliveryRunService::class)->addShipment($run, $shipment, $runDate.' 10:30:00');
    }

    private function shipment(array $attributes = []): Shipment
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B7-'.str()->upper(str()->random(8)),
            'name' => 'ERP Own Fleet',
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B7-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(10000, 99999),
            'fulfilment_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'quote_confirmed',
            'carrier_id' => $carrier->id,
            'service_level' => 'standard',
            'tailgate_required' => false,
        ]);
        $quote = TransportQuote::query()->create([
            'shipment_id' => $shipment->id,
            'carrier_id' => $carrier->id,
            'source' => 'own_fleet',
            'service_level' => 'standard',
            'cost_cents' => 10000,
            'customer_price_cents' => 12000,
            'markup_percent' => 20,
            'eta_days' => 1,
            'quote_stage' => 'final',
            'status' => 'selected',
            'selected_by' => 'system',
            'quoted_at' => now(),
            'expires_at' => now()->addDay(),
            'raw_response' => [
                '_quote_request' => [
                    'receiver' => [
                        'name' => 'Receiving Team',
                        'address' => '88 Test Street',
                        'suburb' => 'Sydney',
                        'state' => 'NSW',
                        'postcode' => '2000',
                    ],
                ],
            ],
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
    }

    private function signatureData(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';
    }
}
