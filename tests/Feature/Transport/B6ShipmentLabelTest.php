<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\Platform\Models\Document;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\OwnFleetLabelPdf;
use App\Modules\Transport\Services\PackageManifest;
use App\Modules\Transport\Services\ShipmentLabelService;
use App\Support\Contracts\CarrierAdapter;
use App\Support\Contracts\DocumentService;
use App\Support\Contracts\JobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B6ShipmentLabelTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_own_fleet_prints_one_barcode_label_per_package_and_archives_the_pdf(): void
    {
        Storage::fake('local');
        $operator = $this->staff('transport_operator');
        $this->actingAs($operator);
        $shipment = $this->shipment('own_fleet');
        $packages = $this->packages();
        $this->mock(PackageManifest::class, function (MockInterface $mock) use ($shipment, $packages): void {
            $mock->shouldReceive('forShipment')
                ->once()
                ->withArgs(fn (Shipment $value): bool => $value->is($shipment))
                ->andReturn($packages);
        });

        $this->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(__('transport.labels.print_own'));
        $response = $this->get(route('transport.shipments.label', $shipment));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename="'.$shipment->shipment_no.'-labels.pdf"');
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        preg_match_all('/\/Type\s*\/Page\b/', $content, $pages);
        $this->assertCount(2, $pages[0]);

        $document = Document::query()->sole();
        $this->assertSame('label', $document->type);
        $this->assertSame('shipment', $document->related_type);
        $this->assertSame($shipment->id, $document->related_id);
        $this->assertSame($shipment->job_id, $document->job_id);
        $this->assertSame($shipment->client_id, $document->client_id);
        $this->assertTrue($document->client_visible);
        $this->assertSame($operator->id, $document->uploaded_by);
        $this->assertSame(strlen($content), $document->size_bytes);
        $this->assertSame($document->id, $shipment->fresh()->waybill_document_id);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertSame($content, Storage::disk('local')->get($document->storage_path));
    }

    public function test_own_fleet_label_requires_measured_packages_and_complete_receiver(): void
    {
        Storage::fake('local');
        $operator = $this->staff('transport_operator');
        $this->actingAs($operator);
        $shipment = $this->shipment('own_fleet');
        $this->mock(PackageManifest::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forShipment')->once()->andReturn([]);
        });

        // 2026-09-10 audit: the failure comes back on the shipment page as an inline error, no longer a bare 422 page.
        $this->get(route('transport.shipments.label', $shipment))
            ->assertRedirect(route('transport.shipments.show', $shipment))
            ->assertSessionHasErrors(['label' => __('transport.labels.no_packages')]);
        $this->assertNull($shipment->fresh()->waybill_document_id);
        $this->assertDatabaseCount('documents', 0);

        $shipment->selectedQuote->update(['raw_response' => ['_quote_request' => ['receiver' => []]]]);
        $this->mock(PackageManifest::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forShipment');
        });
        $this->get(route('transport.shipments.label', $shipment))
            ->assertRedirect(route('transport.shipments.show', $shipment))
            ->assertSessionHasErrors(['label' => __('transport.labels.receiver_missing')]);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_third_party_prints_and_reuses_the_platform_waybill_verbatim(): void
    {
        Storage::fake('local');
        $operator = $this->staff('transport_operator');
        $this->actingAs($operator);
        $shipment = $this->shipment('transdirect', ['booking_ref' => 'TD-B6-100']);
        $waybill = "%PDF-1.4\n% platform waybill\n%%EOF";
        $adapter = new class($waybill) implements CarrierAdapter
        {
            public int $labelCalls = 0;

            public function __construct(private readonly string $waybill) {}

            public function source(): string
            {
                return 'transdirect';
            }

            public function capabilities(): array
            {
                return ['quote' => true, 'book' => true, 'cancel' => true, 'label' => true, 'tracking' => 'poll', 'pod' => 'api'];
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
                $this->labelCalls++;

                return $this->waybill;
            }

            public function tracking(string $bookingRef): array
            {
                return [];
            }
        };
        $this->app->instance(ShipmentLabelService::class, new ShipmentLabelService(
            app(PackageManifest::class),
            app(OwnFleetLabelPdf::class),
            app(DocumentService::class),
            [$adapter],
        ));

        $this->get(route('transport.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(__('transport.labels.print_waybill'));
        $this->get(route('transport.shipments.label', $shipment))
            ->assertOk()
            ->assertContent($waybill);
        $this->assertSame(1, $adapter->labelCalls);
        $this->assertDatabaseHas('documents', [
            'type' => 'waybill',
            'related_type' => 'shipment',
            'related_id' => $shipment->id,
            'mime' => 'application/pdf',
        ]);

        $this->get(route('transport.shipments.label', $shipment))
            ->assertOk()
            ->assertContent($waybill);
        $this->assertSame(1, $adapter->labelCalls, 'A reprint must use the archived platform waybill.');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_label_printing_is_limited_to_transport_coordinators(): void
    {
        Storage::fake('local');
        $this->actingAs($this->staff('transport_operator'));
        $shipment = $this->shipment('own_fleet');

        $this->actingAs($this->staff('finance'))
            ->get(route('transport.shipments.label', $shipment))
            ->assertForbidden();
    }

    /** @return list<array<string, mixed>> */
    private function packages(): array
    {
        return [
            ['id' => 1, 'package_type' => 'carton', 'weight_kg' => '12.500', 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250, 'carton_label' => 'CTN-B6-001'],
            ['id' => 2, 'package_type' => 'pallet', 'weight_kg' => '350.000', 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'carton_label' => 'PLT-B6-002'],
        ];
    }

    private function shipment(string $source, array $attributes = []): Shipment
    {
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $carrier = Carrier::query()->create([
            'code' => 'B6-'.str()->upper(str()->random(8)),
            'name' => $source === 'own_fleet' ? 'ERP Own Fleet' : 'Transdirect',
            'status' => 'active',
        ]);
        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-B6-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(10000, 99999),
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
}
