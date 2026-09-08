<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Platform\Models\Document;
use App\Support\Documents\DocumentDownloader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ERP_PLAN §7 step 8 / §3.8 #5: the client's own invoices (service, weekly storage, supplementary, monthly) with the GST-inclusive
 * PDF Billing stored at issue time. Read-only on Billing's `invoices`; the data-layer client scope (Invoice uses BelongsToClient)
 * limits every query to the signed-in client, and the PDF is served through the shared DocumentDownloader visibility rule.
 */
final class PortalInvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $this->clientId($request);

        $invoices = Invoice::query()
            ->where('status', '!=', 'draft') // drafts are Finance's working copies — never shown to the client
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate(25);

        return view('portal::invoices.index', [
            'invoices' => $invoices,
            'outstandingCents' => (int) Invoice::query()->whereIn('status', ['issued', 'part_paid'])->get(['id', 'total_cents', 'paid_amount_cents'])->sum(fn (Invoice $i) => $i->outstandingCents()),
        ]);
    }

    public function download(Request $request, int $invoice, DocumentDownloader $downloader): StreamedResponse
    {
        $this->clientId($request);
        // Client-scoped lookup (another client's invoice → 404), then the shared visibility rule on the PDF document.
        $invoice = Invoice::query()->where('status', '!=', 'draft')->findOrFail($invoice);
        abort_if($invoice->pdf_document_id === null, 404);

        return $downloader->respond(Document::query()->findOrFail($invoice->pdf_document_id), $request->user());
    }

    private function clientId(Request $request): int
    {
        $user = $request->user();
        abort_unless($user?->isClientUser() && $user->client_id !== null, 403, __('portal.messages.client_only'));

        return (int) $user->client_id;
    }
}
