<?php

namespace Tests\Feature\Portal;

use App\Modules\Platform\Models\Document;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Services\AsnService;
use App\Modules\Warehouse\Services\GoodsReceiptService;
use App\Modules\Warehouse\Services\ReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * 预报入库 in the portal is read-only (lead decision 2026-09-11, CHANGE_REQUESTS #117): staff open the ASN from the client's orders
 * (or by hand); the client follows progress, goods lines and downloads the 入库单 PDFs, and never creates an ASN itself.
 */
class PortalAsnTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_client_reads_its_own_asns_and_downloads_the_goods_receipt_but_cannot_create_one(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'container', 'expected_date' => '2026-10-01', 'reference' => 'PO-77', 'containers' => [['container_no' => 'MSKU1234567', 'size' => '40', 'unpack_mode' => 'loose']]]);
        [$line] = app(AsnService::class)->addLines($asn, [['container_no' => 'MSKU1234567', 'consignment_mark' => 'EDW-01', 'description' => 'Bluetooth speakers', 'expected_cartons' => 12, 'deliver_to_name' => 'Shop A']]);
        $foreign = app(AsnService::class)->create(['client_id' => $this->client()->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel']);

        $this->actingAs($user)->get(route('portal.asns.index'))->assertOk()->assertSee($asn->asn_no)->assertSee('MSKU1234567')->assertSee('PO-77')->assertDontSee($foreign->asn_no)->assertDontSee('portal/asns/create');
        $this->actingAs($user)->get(route('portal.asns.index', ['q' => 'MSKU']))->assertOk()->assertSee($asn->asn_no);
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee('EDW-01')->assertSee('Bluetooth speakers')->assertSee('PO-77')->assertSee(__('portal.asns.receipts.none'));
        $this->actingAs($user)->get(route('portal.asns.show', $foreign))->assertNotFound();
        $this->actingAs($user)->get('/portal/asns/create')->assertNotFound();
        $this->actingAs($user)->post('/portal/asns', [])->assertStatus(405);
        $this->postJson('/portal/api/asns', ['inbound_type' => 'parcel'])->assertNotFound();
        $this->actingAs($this->staff('customer_service'))->get(route('portal.asns.index'))->assertForbidden();

        // Receiving → a draft 入库单, then the PDF once completed; another client never sees it.
        $operator = $this->staff('warehouse_operator');
        $this->actingAs($operator);
        app(ReceivingService::class)->receiveLine($line, ['received_cartons' => 12, 'units' => [['unit_type' => 'carton', 'carton_qty' => 12]]], $this->location($warehouse, 'receiving'), $operator->id);
        $receipt = GoodsReceipt::query()->sole();
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee($receipt->receipt_no)->assertSee(__('portal.asns.receipts.draft'));

        $this->actingAs($operator);
        config(['erp.pdf_cjk_font' => storage_path('fonts/cjk.ttf')]);
        app(GoodsReceiptService::class)->complete($receipt, $operator->id);
        $document = Document::query()->findOrFail($receipt->fresh()->pdf_document_id);
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee(route('portal.documents.download', $document))->assertSee(__('portal.asns.receipts.download'));
        $this->actingAs($user)->get(route('portal.documents.download', $document))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->clientUser($this->client()))->get(route('portal.documents.download', $document))->assertNotFound();
    }

    public function test_a_client_submitted_asn_shows_as_pending_until_customer_service_confirms(): void
    {
        // The confirmation flag stays for a future client channel: nothing in the UI sets created_by_type = client today (CHANGE_REQUESTS #117).
        $client = $this->client();
        $user = $this->clientUser($client);
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'parcel', 'created_by_type' => 'client']);

        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee(__('portal.asns.pending_badge'));
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->get(route('warehouse.asns.index', ['pending' => 1]))->assertOk()->assertSee($asn->asn_no)->assertSee(__('warehouse.asns.client_pending_badge'));
        $this->actingAs($cs)->post(route('warehouse.asns.confirm_client', $asn))->assertRedirect();
        $this->assertNotNull($asn->fresh()->client_confirmed_at);
        $this->actingAs($user)->get(route('portal.asns.show', $asn))->assertOk()->assertSee(__('portal.asns.confirmed_badge'));
    }
}
