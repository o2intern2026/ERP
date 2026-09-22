<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Document;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Services\DocumentNumberResolver;
use App\Support\Contracts\DocumentService;
use App\Support\Documents\DocumentDownloader;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A29 Document Centre: every document by type and related object, upload, client visibility, download.
 * CR #137 (audit ADMIN-10): the upload takes ONE 单号 — JOB- / ASN- / ORD- / SHP- or an invoice number — resolved server side to
 * related_type / related_id / job_id / client_id (DocumentNumberResolver); a number nobody owns is refused in Chinese, so a typo can
 * no longer file a document against nothing. The Job page and the order page post the same form with the number pre-filled
 * (`platform::documents.upload-details`). Uploading and 客户可见 are for admin | customer_service | finance | warehouse_supervisor
 * (route middleware); every staff role still lists and downloads (audit ADMIN-08).
 */
class DocumentController extends Controller
{
    public const RELATED_TYPES = ['job', 'asn', 'order', 'shipment', 'stock_unit', 'invoice', 'client', 'other'];

    public const EDITOR_ROLES = ['admin', 'customer_service', 'finance', 'warehouse_supervisor'];

    public function index(Request $request, DocumentNumberResolver $resolver): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(Enums::DOCUMENT_TYPES)], 'client_id' => ['nullable', 'integer'],
            'related_type' => ['nullable', 'string', 'max:40'], 'related_id' => ['nullable', 'integer'], 'visible' => ['nullable', 'in:0,1'],
            'job_no' => ['nullable', 'string', 'max:40'], // CR #137: the Job page links here with its number
        ]);
        $jobId = null;
        if (! empty($filters['job_no'])) {
            $jobId = Job::query()->where('job_no', trim($filters['job_no']))->value('id') ?? 0; // unknown number → an empty list, not everything
        }

        return view('platform::documents.index', [
            'documents' => Document::query()->with(['client', 'job'])
                ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
                ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
                ->when($filters['related_type'] ?? null, fn ($q, $v) => $q->where('related_type', $v))
                ->when($filters['related_id'] ?? null, fn ($q, $v) => $q->where('related_id', $v))
                ->when($jobId !== null, fn ($q) => $q->where('job_id', $jobId))
                ->when(isset($filters['visible']), fn ($q) => $q->where('client_visible', (bool) $filters['visible']))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'types' => Enums::DOCUMENT_TYPES,
            'relatedTypes' => self::RELATED_TYPES,
            'numberPrefixes' => DocumentNumberResolver::prefixes(),
            'canEdit' => auth()->user()->hasAnyRole(self::EDITOR_ROLES),
        ]);
    }

    public function store(Request $request, DocumentService $documents, DocumentNumberResolver $resolver): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'type' => ['required', Rule::in(Enums::DOCUMENT_TYPES)],
            'document_no' => ['required', 'string', 'max:40'],
            'client_visible' => ['nullable', 'boolean'],
            'back' => ['nullable', 'boolean'], // the Job / order page forms return to where they were
        ]);

        $target = $resolver->resolve($data['document_no']);
        if ($target === null) {
            throw ValidationException::withMessages(['document_no' => __('platform.documents.number_not_found', ['no' => trim($data['document_no'])])]);
        }
        // Audit 2026-09-10: a client-visible document without a client is visible to nobody (DocumentDownloader + client scope) — refuse.
        if (($data['client_visible'] ?? false) && $target['client_id'] === null) {
            throw ValidationException::withMessages(['client_visible' => __('platform.documents.client_required')]);
        }
        $file = $data['file'];
        $path = $file->store('documents/'.now()->format('Y/m'), DocumentDownloader::DISK);

        $documents->attach($data['type'], $target['related_type'], $target['related_id'], $path, [
            'job_id' => $target['job_id'], 'client_id' => $target['client_id'], 'client_visible' => (bool) ($data['client_visible'] ?? false),
            'original_name' => $file->getClientOriginalName(), 'mime' => $file->getClientMimeType(), 'size_bytes' => $file->getSize(),
        ]);

        $flash = __('platform.documents.uploaded_to', ['no' => $target['number']]);

        return $request->boolean('back') ? back()->with('status', $flash) : redirect()->route('platform.documents.index')->with('status', $flash);
    }

    public function visibility(Request $request, Document $document): RedirectResponse
    {
        $data = $request->validate(['client_visible' => ['required', 'boolean']]);
        if ($data['client_visible'] && $document->client_id === null) {
            return back()->withErrors(['client_visible' => __('platform.documents.client_required')]);
        }
        $document->update(['client_visible' => (bool) $data['client_visible']]);

        return back()->with('status', __('platform.documents.visibility_saved'));
    }

    public function download(Document $document, DocumentDownloader $downloader): StreamedResponse
    {
        return $downloader->respond($document, auth()->user());
    }
}
