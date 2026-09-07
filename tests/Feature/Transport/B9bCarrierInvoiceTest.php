<?php

namespace Tests\Feature\Transport;

use App\Modules\MasterData\Models\Carrier;
use App\Modules\MasterData\Models\Client;
use App\Modules\Transport\Models\CarrierCost;
use App\Modules\Transport\Models\CarrierInvoice;
use App\Modules\Transport\Models\CarrierInvoiceLine;
use App\Modules\Transport\Models\Shipment;
use App\Support\Contracts\JobService;
use App\Support\Tenancy\ClientScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

class B9bCarrierInvoiceTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_tables_match_the_frozen_transport_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('carrier_invoices', [
            'carrier_id', 'invoice_no', 'period_from', 'period_to', 'total_cents', 'status', 'document_id',
        ]));
        $this->assertTrue(Schema::hasColumns('carrier_invoice_lines', [
            'carrier_invoice_id', 'shipment_id', 'tracking_number', 'billed_cents', 'expected_cents',
            'variance_cents', 'matched', 'note',
        ]));
    }

    public function test_import_compares_each_shipment_updates_actual_cost_and_exports_differences(): void
    {
        $finance = $this->staff('finance');
        $carrier = Carrier::query()->create(['code' => 'B9B-CARRIER', 'name' => 'B9B Carrier', 'status' => 'active']);
        $exact = $this->shipment($carrier, 'TRACK-EXACT', 10000);
        $variance = $this->shipment($carrier, 'TRACK-VARIANCE', 12000);
        $csv = implode("\n", [
            'tracking_number,billed_cents,note',
            'TRACK-EXACT,10000,base freight',
            'TRACK-VARIANCE,10000,freight',
            'TRACK-VARIANCE,3000,fuel surcharge',
            'TRACK-UNKNOWN,5000,investigate',
        ]);

        $response = $this->actingAs($finance)->post(route('transport.carrier-invoices.store'), [
            'carrier_id' => $carrier->id,
            'invoice_no' => 'INV-B9B-001',
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-31',
            'total_cents' => 28000,
            'statement' => UploadedFile::fake()->createWithContent('carrier-statement.csv', $csv),
        ]);

        $invoice = CarrierInvoice::query()->sole();
        $response->assertRedirect(route('transport.carrier-invoices.show', $invoice));
        $this->assertSame('disputed', $invoice->status);
        $this->assertDatabaseHas('documents', [
            'id' => $invoice->document_id,
            'type' => 'invoice',
            'related_type' => 'carrier_invoice',
            'related_id' => $invoice->id,
            'client_visible' => false,
        ]);
        Storage::disk('local')->assertExists($invoice->document->storage_path);

        $this->assertDatabaseHas('carrier_invoice_lines', [
            'carrier_invoice_id' => $invoice->id,
            'shipment_id' => $exact->id,
            'tracking_number' => 'TRACK-EXACT',
            'billed_cents' => 10000,
            'expected_cents' => 10000,
            'variance_cents' => 0,
            'matched' => true,
        ]);
        $this->assertDatabaseHas('carrier_invoice_lines', [
            'shipment_id' => $variance->id,
            'tracking_number' => 'TRACK-VARIANCE',
            'billed_cents' => 13000,
            'expected_cents' => 12000,
            'variance_cents' => 1000,
            'matched' => false,
            'note' => 'freight; fuel surcharge',
        ]);
        $this->assertDatabaseHas('carrier_invoice_lines', [
            'shipment_id' => null,
            'tracking_number' => 'TRACK-UNKNOWN',
            'expected_cents' => 0,
            'variance_cents' => 5000,
            'matched' => false,
        ]);
        $this->assertSame(10000, $exact->carrierCost->fresh()->actual_cost_cents);
        $this->assertSame(13000, $variance->carrierCost->fresh()->actual_cost_cents);
        $this->assertSame(1000, $variance->carrierCost->fresh()->variance_cents);

        $this->actingAs($finance)->get(route('transport.carrier-invoices.show', $invoice))
            ->assertOk()
            ->assertSee('TRACK-VARIANCE')
            ->assertSee(__('transport.reconciliation.line_disputed'));
        $this->actingAs($finance)->get(route('transport.carrier-invoices.differences', $invoice))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDontSee('TRACK-EXACT')
            ->assertSee('TRACK-VARIANCE')
            ->assertSee('TRACK-UNKNOWN');
    }

    public function test_exact_statement_is_matched_and_cost_data_is_hidden_from_client_requests(): void
    {
        $finance = $this->staff('finance');
        $client = $this->client();
        $carrier = Carrier::query()->create(['code' => 'B9B-EXACT', 'name' => 'Exact Carrier', 'status' => 'active']);
        $this->shipment($carrier, 'TRACK-ONLY', 7500, $client->id);

        $this->actingAs($finance)->post(route('transport.carrier-invoices.store'), [
            'carrier_id' => $carrier->id,
            'invoice_no' => 'INV-B9B-002',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-07',
            'total_cents' => 7500,
            'statement' => UploadedFile::fake()->createWithContent('exact.csv', "tracking_number,billed_cents\nTRACK-ONLY,7500"),
        ])->assertSessionHasNoErrors();

        $invoice = CarrierInvoice::query()->sole();
        $this->assertSame('matched', $invoice->status);
        $this->assertTrue($invoice->lines->sole()->matched);

        $this->actingAs($this->clientUser($client))
            ->get(route('transport.carrier-invoices.index'))
            ->assertForbidden();
        ClientScope::set($client->id);
        try {
            $this->assertSame(0, CarrierInvoice::query()->count());
            $this->assertSame(0, CarrierInvoiceLine::query()->count());
        } finally {
            ClientScope::set(null);
        }
    }

    public function test_invalid_statement_is_rejected_without_partial_records(): void
    {
        $finance = $this->staff('finance');
        $carrier = Carrier::query()->create(['code' => 'B9B-BAD', 'name' => 'Bad CSV Carrier', 'status' => 'active']);

        $this->actingAs($finance)->from(route('transport.carrier-invoices.index'))->post(route('transport.carrier-invoices.store'), [
            'carrier_id' => $carrier->id,
            'invoice_no' => 'INV-B9B-BAD',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-07',
            'total_cents' => 100,
            'statement' => UploadedFile::fake()->createWithContent('bad.csv', "tracking_number,amount\nTRACK-BAD,100"),
        ])->assertSessionHasErrors('statement');

        $this->assertDatabaseCount('carrier_invoices', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    private function shipment(Carrier $carrier, string $trackingNumber, int $expectedCostCents, ?int $clientId = null): Shipment
    {
        $client = $clientId === null ? $this->client() : Client::query()->findOrFail($clientId);
        $job = app(JobService::class)->create($client->id, 'transport_only');
        $shipment = Shipment::query()->create([
            'shipment_no' => 'SHP-B9B-'.str()->upper(str()->random(8)),
            'job_id' => $job['job_id'],
            'client_id' => $client->id,
            'order_id' => random_int(10000, 99999),
            'shipment_type' => 'outbound',
            'status' => 'booked',
            'carrier_id' => $carrier->id,
            'service_level' => 'standard',
            'booking_ref' => 'BOOK-'.$trackingNumber,
            'tracking_number' => $trackingNumber,
        ]);
        CarrierCost::query()->create([
            'shipment_id' => $shipment->id,
            'job_id' => $shipment->job_id,
            'carrier_id' => $carrier->id,
            'expected_cost_cents' => $expectedCostCents,
        ]);

        return $shipment->load('carrierCost');
    }
}
