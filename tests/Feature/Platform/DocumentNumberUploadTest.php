<?php

namespace Tests\Feature\Platform;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Services\DocumentNumberResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsOutboundOrders;
use Tests\Support\CreatesUsers;
use Tests\Support\CreatesWarehouse;
use Tests\TestCase;

/**
 * CR #137 (audit ADMIN-10): the Document Centre takes a 单号 (JOB- / ASN- / ORD- / SHP- / invoice number) instead of database ids,
 * refuses an unknown one, and the Job page / order page carry a small upload box that posts the same form with the number filled in.
 */
class DocumentNumberUploadTest extends TestCase
{
    use BuildsOutboundOrders, CreatesUsers, CreatesWarehouse, RefreshDatabase;

    private function pdf(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'application/pdf');
    }

    public function test_every_supported_number_resolves_to_the_related_object_its_job_and_its_client(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $lines] = $this->stockedAsn($client, $warehouse, [['mark' => 'DOC1', 'cartons' => 3]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $lines[0]->id, 'qty' => 1]]);
        $shipment = DB::table('shipments')->where('order_id', $order->id)->first(['id', 'shipment_no', 'job_id']);
        $job = $asn->job;
        $invoice = Invoice::query()->create(['invoice_no' => 'INV-202609-0001', 'client_id' => $client->id, 'invoice_type' => 'service', 'bill_to_name' => $client->name, 'status' => 'issued', 'total_cents' => 1100]);
        InvoiceLine::query()->create(['invoice_id' => $invoice->id, 'job_id' => $job->id, 'charge_code' => 'WH-PUTAWAY-PLT', 'description' => 'Putaway', 'qty' => 1, 'uom' => 'pallet', 'amount_cents' => 1000, 'tax_treatment' => 'gst_10', 'gst_cents' => 100]);

        $resolver = app(DocumentNumberResolver::class);
        $this->assertSame(['job', $job->id, $job->id, $client->id], array_values(array_slice($resolver->resolve($job->job_no), 0, 4)));
        $this->assertSame(['asn', $asn->id, $job->id, $client->id], array_values(array_slice($resolver->resolve(' '.strtolower($asn->asn_no).' '), 0, 4))); // case / spaces tolerated
        $this->assertSame(['order', $order->id, $order->job_id, $client->id], array_values(array_slice($resolver->resolve($order->order_no), 0, 4)));
        $this->assertSame(['shipment', (int) $shipment->id, (int) $shipment->job_id, $client->id], array_values(array_slice($resolver->resolve($shipment->shipment_no), 0, 4)));
        $this->assertSame(['invoice', $invoice->id, $job->id, $client->id], array_values(array_slice($resolver->resolve('INV-202609-0001'), 0, 4)));
        $this->assertNull($resolver->resolve('ORD-99999999-9999'));
        $this->assertNull($resolver->resolve('nonsense'));
        $this->assertNull($resolver->resolve(''));

        // Through the form: one number, everything else derived — and the flash names the number.
        $cs = $this->staff('customer_service');
        $this->actingAs($cs)->post('/admin/documents', ['file' => $this->pdf('packing.pdf'), 'type' => 'packing_list', 'document_no' => $order->order_no, 'client_visible' => 1])
            ->assertRedirect('/admin/documents')->assertSessionHas('status', __('platform.documents.uploaded_to', ['no' => $order->order_no]));
        $document = Document::query()->latest('id')->firstOrFail();
        $this->assertSame(['order', $order->id, $order->job_id, $client->id, true], [$document->related_type, (int) $document->related_id, (int) $document->job_id, (int) $document->client_id, $document->client_visible]);

        // The list translates the related type, links the Job and filters by Job number (the Job page's 打开单据中心 link).
        $this->actingAs($cs)->get('/admin/documents?job_no='.$order->job->job_no)->assertOk()->assertSee('packing.pdf')->assertSee(__('platform.documents.related_types.order').' #'.$order->id)->assertSee(route('platform.jobs.show', $order->job_id));
        $this->actingAs($cs)->get('/admin/documents?job_no=JOB-00000000-0000')->assertOk()->assertDontSee('packing.pdf');
    }

    public function test_an_unknown_number_is_refused_in_chinese_and_nothing_is_stored(): void
    {
        Storage::fake('local');
        $cs = $this->staff('customer_service');

        $this->actingAs($cs)->from('/admin/documents')->post('/admin/documents', ['file' => $this->pdf(), 'type' => 'pod', 'document_no' => 'ORD-20260101-0042'])
            ->assertRedirect('/admin/documents')->assertSessionHasErrors(['document_no' => __('platform.documents.number_not_found', ['no' => 'ORD-20260101-0042'])]);
        $this->actingAs($cs)->post('/admin/documents', ['file' => $this->pdf(), 'type' => 'pod', 'related_type' => 'shipment', 'related_id' => 42])->assertSessionHasErrors('document_no'); // the old id fields are gone
        $this->assertSame(0, Document::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The form itself asks for the number, not ids, and says so in Chinese.
        $this->actingAs($cs)->get('/admin/documents')->assertOk()->assertSee(__('platform.documents.number'))->assertSee(__('platform.documents.number_hint'))->assertDontSee('name="related_id"', false)->assertDontSee(__('platform.documents.related_id'));
    }

    public function test_the_job_page_and_the_order_page_upload_in_place(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $warehouse = $this->warehouse();
        ['asn' => $asn, 'lines' => $lines] = $this->stockedAsn($client, $warehouse, [['mark' => 'DOC2', 'cartons' => 2]]);
        $order = $this->confirmedOrder($client, $asn->job_id, [['asn_line_id' => $lines[0]->id, 'qty' => 1]]);
        $job = $asn->job;
        $cs = $this->staff('customer_service');
        $jobPage = route('platform.jobs.show', $job);
        $orderPage = route('orders.show', $order);

        // Job page (Platform): the upload box names the Job number and posts back to the page.
        $this->actingAs($cs)->get($jobPage)->assertOk()->assertSee(__('platform.documents.upload_for', ['no' => $job->job_no]))->assertSee(route('platform.documents.index', ['job_no' => $job->job_no]));
        $this->actingAs($cs)->from($jobPage)->post('/admin/documents', ['file' => $this->pdf('job-doc.pdf'), 'type' => 'photo', 'document_no' => $job->job_no, 'back' => 1])
            ->assertRedirect($jobPage)->assertSessionHas('status', __('platform.documents.uploaded_to', ['no' => $job->job_no]));
        $this->assertSame(['job', $job->id], [Document::query()->latest('id')->value('related_type'), (int) Document::query()->latest('id')->value('related_id')]);
        $this->actingAs($cs)->get($jobPage)->assertOk()->assertSee('job-doc.pdf');

        // Order page (Orders view, one @include of the Platform partial): same box with the order number; the attached file lists there.
        $this->actingAs($cs)->get($orderPage)->assertOk()->assertSee(__('platform.documents.upload_for', ['no' => $order->order_no]))->assertSee(route('platform.documents.store'));
        $this->actingAs($cs)->from($orderPage)->post('/admin/documents', ['file' => $this->pdf('order-doc.pdf'), 'type' => 'packing_list', 'document_no' => $order->order_no, 'back' => 1])->assertRedirect($orderPage);
        $this->actingAs($cs)->get($orderPage)->assertOk()->assertSee('order-doc.pdf')->assertDontSee('job-doc.pdf');
        $this->assertSame(['order', $order->id, $order->job_id], [Document::query()->latest('id')->value('related_type'), (int) Document::query()->latest('id')->value('related_id'), (int) Document::query()->latest('id')->value('job_id')]);

        // Roles outside the editor list see neither box (the driver, the floor, the dispatcher) but the pages still open.
        foreach (['transport_operator', 'warehouse_operator'] as $role) {
            $this->actingAs($this->staff($role))->get($jobPage)->assertOk()->assertDontSee(__('platform.documents.upload_for', ['no' => $job->job_no]));
        }
        $this->actingAs($this->staff('dispatcher'))->get($orderPage)->assertOk()->assertDontSee(__('platform.documents.upload_for', ['no' => $order->order_no]));
    }
}
