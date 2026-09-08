<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditNoteService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Enums;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** FIN-3: unbilled pool → draft (per Job / monthly / storage week) → review → issue (PDF, GST, due date) → payments, credit notes. */
class InvoiceController extends Controller
{
    public function unbilled(): View
    {
        $pool = Charge::query()->with(['job', 'client'])->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id')->get()
            ->groupBy('client_id')->map(fn ($byClient) => ['client' => $byClient->first()->client, 'jobs' => $byClient->groupBy('job_id')->map(fn ($byJob) => ['job' => $byJob->first()->job, 'count' => $byJob->count(), 'amount_cents' => (int) $byJob->sum('amount_cents')]), 'amount_cents' => (int) $byClient->sum('amount_cents'), 'has_storage' => $byClient->contains(fn ($c) => $c->chargeCode->category === 'storage')]);

        return view('billing::invoices.unbilled', ['pool' => $pool, 'reviewCount' => Charge::query()->where('status', 'needs_review')->count()]);
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(Enums::INVOICE_STATUSES)], 'client_id' => ['nullable', 'integer']]);

        return view('billing::invoices.index', [
            'invoices' => Invoice::query()->with('client')->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))->orderByDesc('id')->paginate(50)->withQueryString(),
            'filters' => $filters, 'statuses' => Enums::INVOICE_STATUSES, 'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function draftJob(Job $job, InvoiceService $invoices): RedirectResponse
    {
        return $this->tryDraft(fn () => $invoices->draftForJob($job->id));
    }

    public function draftMonthly(Request $request, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', 'integer', Rule::exists('clients', 'id')], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        return $this->tryDraft(fn () => $invoices->draftMonthly((int) $data['client_id'], Carbon::parse($data['from']), Carbon::parse($data['to'])));
    }

    /** Tester feedback #4: any period (week / fortnight / month / custom), service or storage or both, grouped by Job or by order. */
    public function draftPeriod(Request $request, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'scope' => ['required', Rule::in(Enums::INVOICE_SCOPES)], 'group_by' => ['nullable', Rule::in(Enums::INVOICE_GROUPINGS)],
        ]);

        return $this->tryDraft(fn () => $invoices->draftPeriod((int) $data['client_id'], Carbon::parse($data['from']), Carbon::parse($data['to']), $data['scope'], $data['group_by'] ?? null));
    }

    public function draftStorage(Request $request, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate(['client_id' => ['required', 'integer', Rule::exists('clients', 'id')], 'week' => ['required', 'date']]);

        return $this->tryDraft(fn () => $invoices->draftStorageWeek((int) $data['client_id'], Carbon::parse($data['week'])));
    }

    public function show(Invoice $invoice, InvoiceService $invoices): View
    {
        return view('billing::invoices.show', [
            'invoice' => $invoice->load(['client', 'lines.charge.chargeCode', 'jobs', 'payments', 'creditNotes.lines']),
            'groups' => $invoices->groupedLines($invoice),
        ]);
    }

    public function issue(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        try {
            $invoices->issue($invoice);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.issued', ['no' => $invoice->fresh()->invoice_no]));
    }

    public function destroy(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        try {
            $invoices->discardDraft($invoice);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('billing.unbilled')->with('status', __('billing.invoices.discarded'));
    }

    public function pdf(Invoice $invoice, InvoiceService $invoices): Response
    {
        return response($invoices->pdf($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$invoice->invoice_no.'.pdf"']);
    }

    public function payment(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'paid_at' => ['required', 'date'], 'method' => ['required', 'string', 'max:20'], 'reference' => ['nullable', 'string', 'max:100']]);
        try {
            $invoices->recordPayment($invoice, (int) round($data['amount'] * 100), Carbon::parse($data['paid_at']), $data['method'], $data['reference'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.invoices.payment_recorded'));
    }

    public function creditNote(Request $request, Invoice $invoice, CreditNoteService $creditNotes): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.invoice_line_id' => ['nullable', 'integer'], 'lines.*.amount' => ['nullable', 'numeric', 'min:0']]);
        $lines = collect($data['lines'])->filter(fn ($l) => (float) ($l['amount'] ?? 0) > 0)->map(fn ($l) => ['invoice_line_id' => $l['invoice_line_id'] ?? null, 'amount_cents' => (int) round($l['amount'] * 100)])->values()->all();
        try {
            $note = $creditNotes->draft($invoice, $lines, $data['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.credit_notes.drafted', ['id' => $note->id]));
    }

    public function issueCreditNote(Request $request, CreditNote $note, CreditNoteService $creditNotes): RedirectResponse
    {
        try {
            $creditNotes->issue($note, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['credit_note' => $e->getMessage()]);
        }

        return back()->with('status', __('billing.credit_notes.issued', ['no' => $note->fresh()->credit_note_no]));
    }

    private function tryDraft(callable $draft): RedirectResponse
    {
        try {
            $invoice = $draft();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.drafted'));
    }
}
