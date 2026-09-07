<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Transport\Mail\DeliveryPodMail;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TrackingEvent;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Services\ShipmentProgressService;
use App\Modules\Transport\Services\TrackingSyncService;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\ExceptionService;
use App\Support\Contracts\JobService;
use App\Support\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B8DeliveryTrackingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-07 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tracking_table_matches_the_contract_and_polling_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasColumns('tracking_events', [
            'shipment_id', 'status', 'description', 'location', 'source', 'occurred_at', 'raw', 'created_at',
        ]));
        $this->assertSame(['api', 'driver', 'manual'], TransportEnums::TRACKING_SOURCES);
        $operator = $this->staff('transport_operator');
        $this->actingAs($operator);
        $shipment = $this->shipment('transdirect', ['status' => 'booked', 'booking_ref' => 'TD-B8-100']);
        $adapter = $this->trackingAdapter([
            [
                'status' => 'In Transit', 'description' => 'Departed depot', 'location' => 'Melbourne',
                'occurred_at' => '2026-09-07T10:00:00+10:00', 'raw' => ['row' => 1],
            ],
            [
                'status' => 'Delayed', 'description' => 'Weather delay', 'location' => 'Goulburn',
                'occurred_at' => '2026-09-07T11:00:00+10:00', 'raw' => ['row' => 2],
            ],
            [
                'status' => 'Delivered', 'description' => 'Delivered', 'location' => 'Sydney',
                'occurred_at' => '2026-09-07T12:00:00+10:00', 'raw' => ['row' => 3],
            ],
        ]);
        $service = $this->trackingService($adapter);

        $this->assertSame(3, $service->syncActive());
        $this->assertSame(0, $service->syncActive());
        $this->assertSame(3, TrackingEvent::query()->count());
        $this->assertSame('api', TrackingEvent::query()->first()->source);
        $this->assertSame('delivered', $shipment->fresh()->status);
        $this->assertSame('2026-09-07T12:00:00+10:00', $shipment->fresh()->delivered_at->toIso8601String());
        $this->assertDatabaseHas('exceptions', [
            'type' => 'delivery_failed',
            'source_module' => 'transport',
            'source_type' => 'shipment',
            'source_id' => $shipment->id,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('exceptions', [
            'type' => 'manual_transport',
            'source_module' => 'transport',
            'source_type' => 'shipment',
            'source_id' => $shipment->id,
            'status' => 'open',
        ]);

        $this->app->instance(TrackingSyncService::class, $service);
        $this->artisan('transport:sync-tracking')
            ->expectsOutput(__('transport.tracking.synced', ['count' => 0]))
            ->assertSuccessful();
    }

    public function test_driver_delivery_updates_transport_status_and_emails_the_pod(): void
    {
        Mail::fake();
        Storage::fake('local');
        $driver = $this->staff('transport_operator');
        $shipment = $this->shipment('own_fleet', clientAttributes: ['contact_email' => 'client@example.test']);
        $run = app(DeliveryRunService::class)->create('2026-09-07', $driver->id, 'VAN-B8');
        $stop = app(DeliveryRunService::class)->addShipment($run, $shipment);

        $this->actingAs($driver)->post(route('transport.driver.stops.deliver', $stop), [
            'recipient_name' => 'POD Recipient',
            'signature_data' => $this->signatureData(),
            'photos' => [UploadedFile::fake()->image('delivered.jpg')],
        ])->assertSessionHasNoErrors();

        $this->assertSame('delivered', $shipment->fresh()->status);
        $this->assertSame('delivered', $stop->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
        Mail::assertSent(DeliveryPodMail::class, function (DeliveryPodMail $mail): bool {
            return $mail->hasTo('client@example.test')
                && count($mail->attachments()) === 1;
        });
    }

    public function test_carrier_failure_updates_status_and_emits_delivery_failed(): void
    {
        $this->actingAs($this->staff('transport_operator'));
        $shipment = $this->shipment('transdirect', ['status' => 'booked', 'booking_ref' => 'TD-B8-FAIL']);
        $service = $this->trackingService($this->trackingAdapter([[
            'status' => 'Delivery Failed',
            'description' => 'Receiver refused delivery',
            'location' => 'Sydney',
            'occurred_at' => '2026-09-07T13:00:00+10:00',
            'raw' => ['row' => 1],
        ]]));

        $this->assertSame(1, $service->syncShipment($shipment));
        $this->assertSame('failed', $shipment->fresh()->status);
        $event = OutboxEvent::query()->where('event_name', 'delivery.failed')->sole();
        $this->assertSame('carrier_api', $event->payload['reported_by_type']);
        $this->assertSame('Receiver refused delivery', $event->payload['failure_reason']);
        $this->assertSame(1, $event->payload['attempt_no']);
    }

    public function test_driver_failure_updates_status_and_enters_the_shared_exception_centre(): void
    {
        $driver = $this->staff('transport_operator');
        $shipment = $this->shipment('own_fleet');
        $run = app(DeliveryRunService::class)->create('2026-09-07', $driver->id, 'VAN-B8-FAIL');
        $stop = app(DeliveryRunService::class)->addShipment($run, $shipment);

        $this->actingAs($driver)->post(route('transport.driver.stops.fail', $stop), [
            'failure_reason' => 'recipient_unavailable',
        ])->assertSessionHasNoErrors();

        $this->assertSame('failed', $shipment->fresh()->status);
        $this->assertSame('failed', $stop->fresh()->status);
        $this->assertSame('completed', $run->fresh()->status);
        $exception = ExceptionRecord::query()->withoutGlobalScopes()->sole();
        $this->assertSame('delivery_failed', $exception->type);
        $this->assertSame('transport', $exception->source_module);
        $this->assertSame($shipment->id, $exception->source_id);

        $this->actingAs($this->staff('dispatcher'))
            ->get(route('transport.index'))
            ->assertOk()
            ->assertSee(route('platform.exceptions.index', ['source_module' => 'transport']));

        $dispatcher = $this->staff('dispatcher');
        $response = $this->actingAs($dispatcher)
            ->post(route('transport.shipments.redelivery.store', $shipment));
        $redelivery = Shipment::query()->whereKeyNot($shipment->id)->sole();
        $response->assertRedirect(route('transport.shipments.show', $redelivery))
            ->assertSessionHas('status', __('transport.redelivery.created'));
        $this->assertSame('failed', $shipment->fresh()->status);
        $this->assertSame('quote_confirmed', $redelivery->status);
        $this->assertSame('selected', $redelivery->selectedQuote->status);
        $this->assertSame($shipment->id, $redelivery->selectedQuote->raw_response['_redelivery_of']);
    }

    public function test_third_party_pod_is_archived_emitted_and_emailed(): void
    {
        Mail::fake();
        Storage::fake('local');
        $operator = $this->staff('transport_operator');
        $shipment = $this->shipment(
            'transdirect',
            ['status' => 'booked', 'booking_ref' => 'TD-B8-POD'],
            ['contact_email' => 'thirdparty@example.test'],
        );

        $this->actingAs($operator)->post(route('transport.shipments.pod.store', $shipment), [
            'recipient_name' => 'Carrier Recipient',
            'pod_file' => UploadedFile::fake()->createWithContent('carrier-pod.pdf', "%PDF-1.4\ncarrier pod\n%%EOF"),
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.carrier_pod.saved'));

        $pod = $shipment->pods()->sole();
        $this->assertNull($pod->captured_by);
        $this->assertSame([], $pod->photo_document_ids);
        $this->assertSame('delivered', $shipment->fresh()->status);
        $event = OutboxEvent::query()->where('event_name', 'delivery.pod_captured')->sole();
        $this->assertSame('carrier_api', $event->payload['captured_by_type']);
        $this->assertNull($event->payload['captured_by']);
        $this->assertSame($pod->pod_document_id, $event->payload['pod_document_id']);
        Mail::assertSent(DeliveryPodMail::class, fn (DeliveryPodMail $mail): bool => $mail->hasTo('thirdparty@example.test'));
    }

    public function test_extra_charge_form_emits_the_contract_payload_for_billing(): void
    {
        $operator = $this->staff('transport_operator');
        $shipment = $this->shipment('own_fleet');

        $this->actingAs($operator)->post(route('transport.shipments.extra-charges.store', $shipment), [
            'charge_type' => 'waiting',
            'qty' => 1.5,
            'uom' => 'man_hour',
            'cost_cents' => 4500,
            'note' => 'Driver waited for the receiving dock.',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('transport.extra_charges.reported'));

        $event = OutboxEvent::query()->where('event_name', 'delivery.extra_charge')->sole();
        $this->assertEquals([
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'job_id' => $shipment->job_id,
            'client_id' => $shipment->client_id,
            'order_id' => $shipment->order_id,
            'charge_type' => 'waiting',
            'qty' => 1.5,
            'uom' => 'man_hour',
            'cost_cents' => 4500,
            'note' => 'Driver waited for the receiving dock.',
            'reported_by' => $operator->id,
            'occurred_at' => '2026-09-07T14:00:00+10:00',
        ], $event->payload);
    }

    private function trackingService(CarrierAdapter $adapter): TrackingSyncService
    {
        return new TrackingSyncService(
            [$adapter],
            app(ShipmentProgressService::class),
            app(ExceptionService::class),
            app(OutboxPublisher::class),
        );
    }

    /** @param list<array<string, mixed>> $events */
    private function trackingAdapter(array $events): CarrierAdapter
    {
        return new class($events) implements CarrierAdapter
        {
            public function __construct(private readonly array $events) {}

            public function source(): string
            {
                return 'transdirect';
            }

            public function capabilities(): array
            {
                return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => true, 'tracking' => 'poll', 'pod' => 'manual'];
            }

            public function quote(array $request): array
            {
                return [];
            }

            public function book(array $request, string $serviceCode, array $options = []): array
            {
                return [];
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
                return $this->events;
            }
        };
    }

    private function shipment(string $source, array $attributes = [], array $clientAttributes = []): Shipment
    {
        $client = $this->client($clientAttributes);
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B8-'.str()->upper(str()->random(8)),
            'name' => $source === 'own_fleet' ? 'ERP Own Fleet' : 'Transdirect',
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B8-'.str()->upper(str()->random(8)),
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
            'source' => $source,
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
        ]);
        $shipment->update(['selected_quote_id' => $quote->id]);

        return $shipment->refresh();
    }

    private function signatureData(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL+WQAAAABJRU5ErkJggg==';
    }
}
