<?php

namespace Tests\Feature\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DraftOrderParser;
use App\Modules\Platform\Models\Document;
use App\Modules\Warehouse\Services\AsnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/** A12 / OMS-5: a PDF or e-mail becomes a draft order a person checks and confirms — the flow is acceptance, not parsing accuracy. */
class DraftOrderTest extends TestCase
{
    use CreatesUsers, CreatesWarehouse, RefreshDatabase;

    public function test_text_purchase_order_becomes_an_editable_draft_that_staff_link_and_confirm(): void
    {
        Storage::fake('local');
        $cs = $this->staff('customer_service');
        $client = $this->client(['state' => 'NSW']);
        $warehouse = $this->warehouse();
        $asn = app(AsnService::class)->create(['client_id' => $client->id, 'warehouse_id' => $warehouse->id, 'inbound_type' => 'loose_truck']);
        [$asnLine] = app(AsnService::class)->addLines($asn, [['consignment_mark' => 'PO-MARK', 'description' => 'Display stands', 'expected_cartons' => 40]]);

        $text = implode("\n", [
            'PURCHASE ORDER',
            'PO No: PO-2026-0913',
            'Mark: PO-MARK',
            'Deliver to: Amazon BWU2 Receiving',
            'Phone: 02 9999 8888',
            'Address: 1 Distribution Drive, Kemps Creek NSW 2178',
            'Requested delivery date: 2026-09-30',
            'Items',
            'Display stands 12 ctns 180 kg',
            '8 x Shelf brackets 40kg',
        ]);
        $this->actingAs($this->staff('warehouse_operator'))->get(route('orders.drafts.create'))->assertForbidden();
        $this->actingAs($cs)->get(route('orders.drafts.create'))->assertOk()->assertSee(__('orders.drafts.title'));
        $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'standard', 'document' => UploadedFile::fake()->createWithContent('po.xlsx', 'x')])->assertSessionHasErrors('document');

        $response = $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'express', 'document' => UploadedFile::fake()->createWithContent('po-2026-0913.txt', $text)]);

        $order = Order::query()->with('lines')->sole();
        $response->assertSessionHasNoErrors()->assertRedirect(route('orders.show', $order));
        $this->assertSame(['pdf', 'received', 'from_stock', 'express', $client->id], [$order->source, $order->operational_status, $order->order_type, $order->service_level, $order->client_id]);
        $this->assertSame(['PO-2026-0913', 'PO-MARK', 'Amazon BWU2 Receiving', '02 9999 8888', '1 Distribution Drive', 'Kemps Creek', 'NSW', '2178', '2026-09-30'], [
            $order->external_ref, $order->consignment_mark, $order->deliver_to_name, $order->deliver_to_phone, $order->deliver_to_address, $order->deliver_to_suburb, $order->deliver_to_state, $order->deliver_to_postcode, $order->requested_date->toDateString(),
        ]);
        $this->assertSame([['Display stands', 12, 180.0], ['Shelf brackets', 8, 40.0]], $order->lines->map(fn ($l) => [$l->description_cn, $l->carton_qty, (float) $l->actual_weight_kg])->all());
        $this->assertNotNull($order->job_id);

        $document = Document::query()->where('related_type', 'order')->where('related_id', $order->id)->sole();
        $this->assertSame(['packing_list', false, 'po-2026-0913.txt'], [$document->type, $document->client_visible, $document->original_name]);
        Storage::disk('local')->assertExists($document->storage_path);
        $this->assertDatabaseHas('order_imports', ['client_id' => $client->id, 'source' => 'pdf', 'status' => 'imported', 'row_count' => 2, 'document_id' => $document->id, 'created_by' => $cs->id]);

        // The draft page: banner, line editing, ASN linking, then the normal confirmation.
        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.drafts.banner_title'))->assertSee(__('orders.drafts.edit_line'))->assertSee($asn->asn_no);
        $this->actingAs($cs)->get(route('orders.drafts.create'))->assertOk()->assertSee($order->order_no)->assertSee(__('orders.drafts.fields.external_ref'));
        $this->actingAs($cs)->post(route('orders.confirm', $order))->assertSessionHasErrors('order'); // unlinked from_stock lines cannot be confirmed (A7)

        [$first, $second] = $order->lines;
        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $first]), ['description_cn' => '展示架', 'description_en' => 'Display stands', 'package_type' => 'carton', 'carton_qty' => 10, 'asn_line_id' => $asnLine->id])->assertSessionHasNoErrors();
        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $second]), ['description_en' => 'Shelf brackets', 'carton_qty' => 8, 'asn_line_id' => $asnLine->id])->assertSessionHasNoErrors();
        $this->actingAs($cs)->post(route('orders.lines.store', $order), ['description_cn' => '说明书', 'carton_qty' => 1, 'asn_line_id' => $asnLine->id])->assertSessionHasNoErrors();
        $order->refresh()->load('lines');
        $this->assertSame([['展示架', 10], ['Shelf brackets', 8], ['说明书', 1]], $order->lines->map(fn ($l) => [$l->description_cn ?: $l->description_en, $l->carton_qty])->all());
        $this->assertSame(3, $order->lines->whereNotNull('asn_line_id')->count());
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'actor_id' => $cs->id, 'note' => __('orders.drafts.timeline.line_added', ['description' => '说明书', 'qty' => 1])]);

        $this->actingAs($cs)->post(route('orders.confirm', $order))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $order->fresh()->operational_status);
        $this->actingAs($cs)->patch(route('orders.lines.update', [$order, $first]), ['description_cn' => 'late', 'carton_qty' => 1])->assertForbidden();
    }

    public function test_pdf_and_email_uploads_are_read_without_external_tools(): void
    {
        Storage::fake('local');
        $cs = $this->staff('customer_service');
        $client = $this->client();

        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /Contents 4 0 R >> endobj\n"
            ."4 0 obj << /Length 160 >>\nstream\nBT /F1 12 Tf (Order No: PO-778) Tj T* (Consignee: Bunnings Hoppers Crossing) Tj T* (Address: 2 Old Geelong Rd, Hoppers Crossing VIC 3029) Tj T* [(Garden hose) ( 24 cartons)] TJ ET\nendstream\nendobj\ntrailer << /Root 1 0 R >>\n%%EOF";
        $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'standard', 'document' => UploadedFile::fake()->createWithContent('po-778.pdf', $pdf)])->assertSessionHasNoErrors();
        $fromPdf = Order::query()->latest('id')->firstOrFail()->load('lines');
        $this->assertSame(['PO-778', 'Bunnings Hoppers Crossing', 'Hoppers Crossing', 'VIC', '3029'], [$fromPdf->external_ref, $fromPdf->deliver_to_name, $fromPdf->deliver_to_suburb, $fromPdf->deliver_to_state, $fromPdf->deliver_to_postcode]);
        $this->assertSame([['Garden hose', 24]], $fromPdf->lines->map(fn ($l) => [$l->description_cn, $l->carton_qty])->all());

        $eml = "From: buyer@example.com\r\nTo: orders@erp.local\r\nSubject: PO 991\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            ."Ship to: =E4=BC=97=E5=8D=96 Pty Ltd\r\nAddress: 9 Harbour St, Sydney NSW 2000\r\nDelivery date: 15/10/2026\r\n=E5=B1=95=E7=A4=BA=E6=9F=9C 5 =E7=AE=B1\r\n";
        $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'standard', 'document' => UploadedFile::fake()->createWithContent('po-991.eml', $eml)])->assertSessionHasNoErrors();
        $fromEmail = Order::query()->latest('id')->firstOrFail()->load('lines');
        $this->assertSame(['众卖 Pty Ltd', '9 Harbour St', 'Sydney', 'NSW', '2000', '2026-10-15'], [$fromEmail->deliver_to_name, $fromEmail->deliver_to_address, $fromEmail->deliver_to_suburb, $fromEmail->deliver_to_state, $fromEmail->deliver_to_postcode, $fromEmail->requested_date->toDateString()]);
        $this->assertSame([['展示柜', 5]], $fromEmail->lines->map(fn ($l) => [$l->description_cn, $l->carton_qty])->all());
    }

    public function test_unreadable_file_still_produces_a_placeholder_draft_for_manual_completion(): void
    {
        Storage::fake('local');
        $cs = $this->staff('dispatcher');
        $client = $this->client(['state' => 'QLD']);

        $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'standard', 'document' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\nbinary garbage without text operators")])
            ->assertSessionHasNoErrors();

        $order = Order::query()->with('lines')->sole();
        $placeholder = __('orders.drafts.placeholder');
        $this->assertSame([$placeholder, $placeholder, 'QLD', '0000', 1], [$order->deliver_to_name, $order->deliver_to_address, $order->deliver_to_state, $order->deliver_to_postcode, $order->lines->count()]);
        $this->assertSame($placeholder, $order->lines->first()->description_cn);
        $this->assertDatabaseHas('order_imports', ['client_id' => $client->id, 'source' => 'pdf', 'row_count' => 0]);
        $this->assertDatabaseHas('order_events', ['order_id' => $order->id, 'note' => __('orders.drafts.timeline.created', ['file' => 'scan.pdf', 'fields' => __('orders.drafts.nothing_matched')])]);
        $this->actingAs($cs)->get(route('orders.show', $order))->assertOk()->assertSee(__('orders.drafts.banner'));

        // A second upload of the same PO number does not collide with the per-client unique reference.
        $this->assertSame(['PO-1'], [app(DraftOrderParser::class)->parse('PO: PO-1')['external_ref']]);
        $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'standard', 'document' => UploadedFile::fake()->createWithContent('a.txt', 'PO: PO-1')])->assertSessionHasNoErrors();
        $this->actingAs($cs)->post(route('orders.drafts.store'), ['client_id' => $client->id, 'service_level' => 'standard', 'document' => UploadedFile::fake()->createWithContent('b.txt', 'PO: PO-1')])->assertSessionHasNoErrors();
        $this->assertSame(['PO-1', 'PO-1-2'], Order::query()->whereNotNull('external_ref')->orderBy('id')->pluck('external_ref')->all());
    }
}
