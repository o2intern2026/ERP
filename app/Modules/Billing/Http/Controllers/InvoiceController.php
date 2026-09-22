<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Services\CreditNoteService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Approval;
use App\Modules\Platform\Models\ExceptionRecord;
use App\Modules\Platform\Models\Job;
use App\Modules\Platform\Services\ApprovalService;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** FIN-3: unbilled pool → draft (per Job / monthly / storage week) → review → issue (PDF, GST, due date) → payments, credit notes. */
class InvoiceController extends Controller
{
    public function unbilled(): View
    {
        // Audit 2026-09-10: storage is invoiced weekly, never per Job (InvoiceService::draftForJob) — split every row so the
        // page only offers 按此 Job 开票 where it can succeed and the figures match what the button will draft.
        $isStorage = fn (Charge $c) => $c->chargeCode->category === 'storage';
        $split = fn ($charges) => ['count' => $charges->count(), 'amount_cents' => (int) $charges->sum('amount_cents'),
            'service_count' => $charges->reject($isStorage)->count(), 'service_amount_cents' => (int) $charges->reject($isStorage)->sum('amount_cents'),
            'storage_count' => $charges->filter($isStorage)->count(), 'storage_amount_cents' => (int) $charges->filter($isStorage)->sum('amount_cents'),
            // The period form defaults to the span and scope of what is actually in the pool, so the header figure and the button agree (audit 2026-09-10).
            'period_from' => $charges->min('charge_date')?->toDateString(), 'period_to' => $charges->max('charge_date')?->toDateString(),
            'default_scope' => $charges->contains($isStorage) ? ($charges->every($isStorage) ? 'storage' : 'all') : 'service'];
        $pool = Charge::query()->with(['job', 'client', 'chargeCode'])->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id')->get()
            ->groupBy('client_id')->map(fn ($byClient) => ['client' => $byClient->first()->client, 'jobs' => $byClient->groupBy('job_id')->map(fn ($byJob) => ['job' => $byJob->first()->job] + $split($byJob)), 'has_storage' => $byClient->contains($isStorage)] + $split($byClient));

        // Audit 2026-09-22 FIN-03 (CR #132): what is NOT in the pool yet, per client — needs_review rows ($0 placeholders, POA) and open missing-rate exceptions.
        $reviewByClient = Charge::query()->where('status', 'needs_review')->selectRaw('client_id, COUNT(*) as n')->groupBy('client_id')->pluck('n', 'client_id');
        $missingByClient = ExceptionRecord::query()->withoutGlobalScopes()->where('type', 'missing_rate')->where('status', '!=', 'resolved')->selectRaw('client_id, COUNT(*) as n')->groupBy('client_id')->pluck('n', 'client_id');

        // A client whose ONLY revenue is unpriced never reaches the pool — list it below the pool so it is not forgotten at month-end.
        $attentionIds = $reviewByClient->keys()->merge($missingByClient->keys())->unique()->diff($pool->keys());

        return view('billing::invoices.unbilled', [
            'pool' => $pool, 'reviewCount' => (int) $reviewByClient->sum(), 'missingRateCount' => (int) $missingByClient->sum(),
            'reviewByClient' => $reviewByClient, 'missingByClient' => $missingByClient,
            'attentionClients' => Client::query()->whereIn('id', $attentionIds)->orderBy('name')->get(['id', 'name']),
        ]);
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

    public function show(Invoice $invoice, InvoiceService $invoices, ApprovalService $approvals): View
    {
        $invoice->load(['client', 'lines.charge.chargeCode', 'jobs', 'payments', 'creditNotes.lines']);
        // Audit 2026-09-10: the 开出 button is gated on the second person's approval, so the page shows that state (same pattern as RateCardController::show).
        $noteIds = $invoice->creditNotes->pluck('id');
        $pending = Approval::query()->where('type', 'credit_note')->where('subject_type', 'credit_note')->whereIn('subject_id', $noteIds)->where('status', 'pending')->pluck('subject_id')->all();

        return view('billing::invoices.show', [
            'invoice' => $invoice,
            'groups' => $invoices->groupedLines($invoice),
            'creditNoteApproved' => $noteIds->mapWithKeys(fn ($id) => [$id => $approvals->isApproved('credit_note', 'credit_note', $id)])->all(),
            'creditNotePending' => $pending,
        ] + ($invoice->status === 'draft' ? $this->unpricedFor($invoice) : ['unpricedCharges' => collect(), 'unpricedExceptions' => collect()]));
    }

    /**
     * Audit 2026-09-22 FIN-03 (CR #132): the draft page warns — it does not block — about revenue that is NOT on this draft because
     * it is still unpriced: needs_review charges (missing-rate placeholders, POA) and open missing-rate exceptions of the same Job(s)
     * (per-Job draft) or of the client inside the period (period / monthly / storage draft). An exception already represented by a
     * listed placeholder is not listed twice.
     *
     * @return array{unpricedCharges: Collection<int, Charge>, unpricedExceptions: Collection<int, ExceptionRecord>}
     */
    private function unpricedFor(Invoice $invoice): array
    {
        $jobIds = $invoice->jobs->pluck('id');
        $charges = Charge::query()->with(['chargeCode', 'job'])->where('client_id', $invoice->client_id)->where('status', 'needs_review')
            ->when($invoice->period_from === null, fn ($q) => $q->whereIn('job_id', $jobIds), fn ($q) => $q->whereBetween('charge_date', [$invoice->period_from->toDateString(), $invoice->period_to->toDateString()]))
            ->orderBy('id')->get();
        $linked = $charges->map(fn (Charge $c) => (int) ($c->calculation_snapshot_json['exception_id'] ?? 0))->filter();
        $exceptions = ExceptionRecord::query()->withoutGlobalScopes()->with('job')->where('type', 'missing_rate')->where('status', '!=', 'resolved')->where('client_id', $invoice->client_id)
            ->whereNotIn('id', $linked)
            ->when($invoice->period_from === null, fn ($q) => $q->whereIn('job_id', $jobIds), fn ($q) => $q->where(fn ($w) => $w->whereIn('job_id', $jobIds)->orWhereBetween('created_at', [$invoice->period_from->startOfDay(), $invoice->period_to->endOfDay()])))
            ->orderBy('id')->get();

        return ['unpricedCharges' => $charges, 'unpricedExceptions' => $exceptions];
    }

    public function issue(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        try {
            $invoices->issue($invoice);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => RuleViolation::display($e)]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.issued', ['no' => $invoice->fresh()->invoice_no]));
    }

    public function destroy(Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        try {
            $invoices->discardDraft($invoice);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => RuleViolation::display($e)]);
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
            return back()->withErrors(['amount' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('billing.invoices.payment_recorded'));
    }

    public function creditNote(Request $request, Invoice $invoice, CreditNoteService $creditNotes): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.invoice_line_id' => ['nullable', 'integer'], 'lines.*.amount' => ['nullable', 'numeric', 'min:0']]);
        $lines = collect($data['lines'])->filter(fn ($l) => (float) ($l['amount'] ?? 0) > 0)->map(fn ($l) => ['invoice_line_id' => $l['invoice_line_id'] ?? null, 'amount_cents' => (int) round($l['amount'] * 100)])->values()->all();
        if ($error = $this->creditCapError($invoice, $lines)) {
            return back()->withErrors(['lines' => $error])->withInput();
        }
        try {
            $note = $creditNotes->draft($invoice, $lines, $data['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => RuleViolation::display($e)])->withInput();
        }

        return back()->with('status', __('billing.credit_notes.drafted', ['id' => $note->id]));
    }

    public function issueCreditNote(Request $request, CreditNote $note, CreditNoteService $creditNotes): RedirectResponse
    {
        try {
            $creditNotes->issue($note, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['credit_note' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('billing.credit_notes.issued', ['no' => $note->fresh()->credit_note_no]));
    }

    /**
     * Audit 2026-09-22 FIN-02: a credit note line can never exceed what is left to credit on its invoice line (line amount minus every
     * draft / issued credit note already on it) — the browser `max` attribute was the only guard before. Free-form lines (no invoice
     * line) are not capped. Returns the Chinese message of the first line over the cap, or null.
     *
     * @param  list<array{invoice_line_id: ?int, amount_cents: int}>  $lines
     */
    private function creditCapError(Invoice $invoice, array $lines): ?string
    {
        $invoiceLines = $invoice->lines()->get()->keyBy('id');
        $credited = DB::table('credit_note_lines')->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->whereIn('credit_note_lines.invoice_line_id', $invoiceLines->keys())->where('credit_notes.status', '!=', 'cancelled')
            ->selectRaw('credit_note_lines.invoice_line_id, SUM(credit_note_lines.amount_cents) as credited')->groupBy('credit_note_lines.invoice_line_id')->pluck('credited', 'invoice_line_id');
        $requested = collect($lines)->filter(fn ($l) => $l['invoice_line_id'] !== null && isset($invoiceLines[(int) $l['invoice_line_id']]))->groupBy('invoice_line_id')->map(fn ($g) => (int) $g->sum('amount_cents'));
        foreach ($requested as $lineId => $amount) {
            $line = $invoiceLines[(int) $lineId];
            $max = max(0, (int) $line->amount_cents - (int) ($credited[$lineId] ?? 0));
            if ($amount > $max) {
                return __('billing.credit_notes.errors.exceeds_line', ['code' => $line->charge_code, 'description' => $line->description, 'max' => Money::cents($max)->format(), 'amount' => Money::cents($amount)->format()]);
            }
        }

        return null;
    }

    private function tryDraft(callable $draft): RedirectResponse
    {
        try {
            $invoice = $draft();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => RuleViolation::display($e)]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.drafted'));
    }
}
