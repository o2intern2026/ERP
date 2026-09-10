<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Models\TransportQuote;
use App\Modules\Transport\Services\PackageManifest;
use App\Modules\Transport\Services\ShipmentStatusMachine;
use App\Support\Contracts\JobService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B5ShipmentTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_b5_tables_match_the_transport_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('shipments', [
            'shipment_no', 'job_id', 'client_id', 'order_id', 'fulfilment_id', 'shipment_type', 'status',
            'selected_quote_id', 'carrier_id', 'service_level', 'booking_ref', 'tracking_number',
            'waybill_document_id', 'consignment_note_document_id', 'tailgate_required', 'delivery_run_id',
            'dispatched_at', 'delivered_at',
        ]));
        $this->assertTrue(Schema::hasColumns('transport_quotes', [
            'shipment_id', 'carrier_id', 'source', 'service_level', 'cost_cents', 'customer_price_cents',
            'markup_percent', 'eta_days', 'is_recommended', 'is_cheapest', 'is_fastest', 'quote_stage',
            'status', 'selected_by', 'selected_by_user_id', 'quoted_at', 'expires_at', 'raw_response',
        ]));
    }

    public function test_outbound_status_machine_follows_contract_enums(): void
    {
        [$shipment] = $this->shipment();
        $machine = app(ShipmentStatusMachine::class);

        $shipment = $machine->transition($shipment, 'quoted');
        $this->assertSame('quoted', $shipment->status);
        $shipment = $machine->transition($shipment, 'quote_confirmed');
        $this->assertSame('quote_confirmed', $shipment->status);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(__('transport.tracking.invalid_transition', ['from' => 'quote_confirmed', 'to' => 'delivered']));
        $machine->transition($shipment, 'delivered');
    }

    public function test_return_status_machine_follows_contract_enums(): void
    {
        [$shipment] = $this->shipment([
            'shipment_type' => 'return',
            'status' => 'return_requested',
        ]);
        $machine = app(ShipmentStatusMachine::class);

        $shipment = $machine->transition($shipment, 'return_in_transit');
        $this->assertSame('return_in_transit', $shipment->status);
        $shipment = $machine->transition($shipment, 'arrived_warehouse');
        $this->assertSame('arrived_warehouse', $shipment->status);
        $this->assertSame([], $machine->allowedTransitions($shipment));
    }

    public function test_quote_candidates_display_all_sources_prices_eta_and_acceptance_flags(): void
    {
        [$shipment, $client, $job] = $this->shipment(['status' => 'quoted']);
        $carriers = collect([
            ['code' => 'OWN-B5', 'name' => 'Own Fleet', 'source' => 'own_fleet', 'cost' => 5000, 'price' => 7500, 'eta' => 1, 'fastest' => true],
            ['code' => 'TD-B5', 'name' => 'Transdirect', 'source' => 'transdirect', 'cost' => 6000, 'price' => 7200, 'eta' => 3],
            ['code' => 'EIZ-B5', 'name' => 'EIZ', 'source' => 'eiz', 'cost' => 5500, 'price' => 6600, 'eta' => 4, 'recommended' => true, 'cheapest' => true],
        ])->map(function (array $option) use ($shipment): TransportQuote {
            $carrier = Carrier::query()->create([
                'code' => $option['code'],
                'name' => $option['name'],
                'status' => 'active',
            ]);

            return TransportQuote::query()->create([
                'shipment_id' => $shipment->id,
                'carrier_id' => $carrier->id,
                'source' => $option['source'],
                'service_level' => 'standard',
                'cost_cents' => $option['cost'],
                'customer_price_cents' => $option['price'],
                'markup_percent' => 20,
                'eta_days' => $option['eta'],
                'is_recommended' => $option['recommended'] ?? false,
                'is_cheapest' => $option['cheapest'] ?? false,
                'is_fastest' => $option['fastest'] ?? false,
                'quote_stage' => 'final',
                'status' => 'quoted',
                'quoted_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
        });

        $selected = $carriers->last();
        $shipment->update([
            'selected_quote_id' => $selected->id,
            'carrier_id' => $selected->carrier_id,
            'service_level' => 'standard',
        ]);

        $response = $this->actingAs($this->staff('transport_operator'))
            ->get(route('transport.shipments.show', $shipment));

        $response->assertOk()
            ->assertSee('Own Fleet')
            ->assertSee('Transdirect')
            ->assertSee('EIZ')
            ->assertSee('$75.00')
            ->assertSee('$72.00')
            ->assertSee('$66.00')
            ->assertSee(__('transport.flags.recommended'))
            ->assertSee(__('transport.flags.cheapest'))
            ->assertSee(__('transport.flags.fastest'));

        $this->assertSame($client->id, $shipment->client_id);
        $this->assertSame($job['job_id'], $shipment->job_id);
    }

    public function test_consignment_note_pdf_lists_the_wms_package_manifest(): void
    {
        [$shipment] = $this->shipment([
            'status' => 'quoted',
            'service_level' => 'standard',
            'tailgate_required' => true,
        ]);

        $packages = [
            ['id' => 1, 'package_type' => 'carton', 'weight_kg' => '12.500', 'length_mm' => 400, 'width_mm' => 300, 'height_mm' => 250, 'carton_label' => 'CTN-B5-001'],
            ['id' => 2, 'package_type' => 'pallet', 'weight_kg' => '350.000', 'length_mm' => 1200, 'width_mm' => 1200, 'height_mm' => 1400, 'carton_label' => 'PLT-B5-001'],
        ];

        $this->mock(PackageManifest::class, function (MockInterface $mock) use ($shipment, $packages): void {
            $mock->shouldReceive('forShipment')->once()->withArgs(fn (Shipment $value): bool => $value->is($shipment))->andReturn($packages);
        });

        $html = view('transport::shipments.consignment-note', [
            'shipment' => $shipment->load(['client', 'job', 'carrier']),
            'packages' => collect($packages),
            'packageCount' => 2,
            'totalWeightKg' => 362.5,
            'generatedAt' => now(),
        ])->render();
        $this->assertStringContainsString('CTN-B5-001', $html);
        $this->assertStringContainsString('PLT-B5-001', $html);

        $response = $this->actingAs($this->staff('transport_operator'))
            ->get(route('transport.shipments.consignment-note', $shipment));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    /** @return array{Shipment, Client, array{job_id:int, job_no:string}} */
    private function shipment(array $attributes = []): array
    {
        $user = $this->staff('transport_operator');
        $this->actingAs($user);
        $client = $this->client();
        $job = app(JobService::class)->create($client->id, 'transport_only');

        $shipment = Shipment::query()->create($attributes + [
            'shipment_no' => 'SHP-'.str()->upper(str()->random(10)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(1000, 9999),
            'fulfilment_id' => null,
            'shipment_type' => 'outbound',
            'status' => 'quoting',
            'tailgate_required' => false,
        ]);

        return [$shipment, $client, $job];
    }
}
