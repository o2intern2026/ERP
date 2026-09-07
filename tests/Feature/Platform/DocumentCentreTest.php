<?php

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\Document;
use App\Support\Documents\DocumentDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesUsers;
use Tests\TestCase;

/** A29: upload, list, visibility toggle, download; the downloader enforces client visibility for portal use. */
class DocumentCentreTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    public function test_upload_list_toggle_and_download(): void
    {
        Storage::fake('local');
        $client = $this->client();
        $cs = $this->staff('customer_service');

        $this->actingAs($cs)->post('/admin/documents', [
            'file' => UploadedFile::fake()->create('pod-42.pdf', 30, 'application/pdf'), 'type' => 'pod', 'related_type' => 'shipment', 'related_id' => 42, 'client_id' => $client->id, 'client_visible' => 0,
        ])->assertRedirect('/admin/documents');

        $document = Document::query()->firstOrFail();
        $this->assertSame(['pod', 'shipment', 42, false], [$document->type, $document->related_type, (int) $document->related_id, $document->client_visible]);
        Storage::disk('local')->assertExists($document->storage_path);

        $this->actingAs($cs)->get('/admin/documents?type=pod')->assertOk()->assertSee('pod-42.pdf');
        $this->actingAs($cs)->get('/admin/documents?type=invoice')->assertOk()->assertDontSee('pod-42.pdf');
        $this->actingAs($cs)->post("/admin/documents/{$document->id}/visibility", ['client_visible' => 1])->assertRedirect();
        $this->assertTrue($document->fresh()->client_visible);

        $response = $this->actingAs($cs)->get("/admin/documents/{$document->id}/download");
        $response->assertOk();
        $this->assertStringContainsString('pod-42.pdf', $response->headers->get('content-disposition'));
    }

    public function test_downloader_lets_clients_see_only_their_visible_documents(): void
    {
        Storage::fake('local');
        $a = $this->client();
        $b = $this->client();
        $downloader = app(DocumentDownloader::class);
        $visible = Document::query()->create(['type' => 'pod', 'related_type' => 'shipment', 'related_id' => 1, 'client_id' => $a->id, 'client_visible' => true, 'storage_path' => 'documents/a.pdf']);
        $hidden = Document::query()->create(['type' => 'photo', 'related_type' => 'stock_unit', 'related_id' => 1, 'client_id' => $a->id, 'client_visible' => false, 'storage_path' => 'documents/b.jpg']);
        $others = Document::query()->create(['type' => 'pod', 'related_type' => 'shipment', 'related_id' => 2, 'client_id' => $b->id, 'client_visible' => true, 'storage_path' => 'documents/c.pdf']);
        Storage::disk('local')->put('documents/a.pdf', '%PDF-1.4 test');

        $clientUser = $this->clientUser($a);
        $this->assertTrue($downloader->canDownload($visible, $clientUser));
        $this->assertFalse($downloader->canDownload($hidden, $clientUser));
        $this->assertFalse($downloader->canDownload($others, $clientUser));
        $this->assertTrue($downloader->canDownload($hidden, $this->staff('dispatcher')));
        $this->assertFalse($downloader->canDownload($visible, null));

        $this->assertSame(200, $downloader->respond($visible, $clientUser)->getStatusCode());
        $this->expectException(HttpException::class);
        $downloader->respond($hidden, $clientUser);
    }
}
