<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\Document;
use App\Support\Documents\DocumentDownloader;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A9-p / services.md §8: portal downloads (POD, photos) go through the single visibility rule — never a direct storage link. */
final class PortalDocumentController extends Controller
{
    public function __invoke(Request $request, int $document, DocumentDownloader $downloader): StreamedResponse
    {
        // Client-scoped lookup (another client's document → 404), then the shared visibility rule (client_visible → else 403).
        return $downloader->respond(Document::query()->findOrFail($document), $request->user());
    }
}
