<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Document;
use App\Support\Contracts\DocumentService;
use App\Support\Documents\DocumentDownloader;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A29 Document Centre: every document by type and related object, upload, client visibility, download. */
class DocumentController extends Controller
{
    public const RELATED_TYPES = ['job', 'asn', 'order', 'shipment', 'stock_unit', 'invoice', 'client', 'other'];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(Enums::DOCUMENT_TYPES)], 'client_id' => ['nullable', 'integer'],
            'related_type' => ['nullable', 'string', 'max:40'], 'related_id' => ['nullable', 'integer'], 'visible' => ['nullable', 'in:0,1'],
        ]);

        return view('platform::documents.index', [
            'documents' => Document::query()->with(['client', 'job'])
                ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
                ->when($filters['related_type'] ?? null, fn ($q, $v) => $q->where('related_type', $v))
                ->when($filters['related_id'] ?? null, fn ($q, $v) => $q->where('related_id', $v))
                ->when(isset($filters['visible']), fn ($q) => $q->where('client_visible', (bool) $filters['visible']))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'types' => Enums::DOCUMENT_TYPES,
            'relatedTypes' => self::RELATED_TYPES,
        ]);
    }

    public function store(Request $request, DocumentService $documents): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'type' => ['required', Rule::in(Enums::DOCUMENT_TYPES)],
            'related_type' => ['required', 'string', 'max:40'],
            'related_id' => ['required', 'integer', 'min:1'],
            'job_id' => ['nullable', 'integer', Rule::exists('jobs', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'client_visible' => ['nullable', 'boolean'],
        ]);
        $file = $data['file'];
        $path = $file->store('documents/'.now()->format('Y/m'), DocumentDownloader::DISK);

        $documents->attach($data['type'], $data['related_type'], (int) $data['related_id'], $path, [
            'job_id' => $data['job_id'] ?? null, 'client_id' => $data['client_id'] ?? null, 'client_visible' => (bool) ($data['client_visible'] ?? false),
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size_bytes' => $file->getSize(),
        ]);

        return redirect()->route('platform.documents.index')->with('status', __('platform.documents.uploaded'));
    }

    public function visibility(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);
        $document->update(['client_visible' => (bool) $data['client_visible']]);

        return back()->with('status', __('platform.documents.visibility_saved'));
    }

    public function download(Document $document, DocumentDownloader $downloader): StreamedResponse
    {
        return $downloader->respond($document, auth()->user());
    }
}
