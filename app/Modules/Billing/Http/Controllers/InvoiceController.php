<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\CreditNote;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceLine;
use App\Modules\Billing\Models\Payment;
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
use Spatie\Activitylog\Models\Activity;

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
            'draftsByJob' => $this->openDraftsByJob(),
        ]);
    }

    /**
     * Audit 2026-09-22 FIN-10 (CR #140): the open (non-storage) draft each Job already sits on, so the pool offers 并入该草稿 instead of a
     * second 按此 Job 开票 fragment. A per-Job draft (no period) wins over a period draft; otherwise the newest.
     *
     * @return array<int, Invoice> job id → draft
     */
    private function openDraftsByJob(): array
    {
        $byJob = [];
        foreach (Invoice::query()->with('jobs:jobs.id')->where('status', 'draft')->where('invoice_type', '!=', 'storage')->orderByDesc('id')->get() as $draft) {
            foreach ($draft->jobs as $job) {
                if (! isset($byJob[$job->id]) || ($draft->period_from === null && $byJob[$job->id]->period_from !== null)) {
                    $byJob[$job->id] = $draft;
                }
            }
        }

        return $byJob;
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

    public function show(Request $request, Invoice $invoice, InvoiceService $invoices, ApprovalService $approvals): View
    {
        $invoice->load(['client', 'lines.charge.chargeCode', 'jobs', 'payments.voidedBy', 'creditNotes.lines']);
        // Audit 2026-09-10: the 开出 button is gated on the second person's approval, so the page shows that state (same pattern as RateCardController::show).
        $noteIds = $invoice->creditNotes->pluck('id');
        $pending = Approval::query()->where('type', 'credit_note')->where('subject_type', 'credit_note')->whereIn('subject_id', $noteIds)->where('status', 'pending')->pluck('subject_id')->all();
        // Audit 2026-09-22 FIN-16 (CR #140): the charges list sends 去开冲减单 here with ?credit_line= — the form opens with that line's remaining amount filled in.
        $creditLine = (int) $request->query('credit_line', 0);

        // CR #142: unpriced items in scope BLOCK 开出发票 unless Finance ticks the override; an issued invoice shows whether that happened.
        $unpriced = $invoice->status === 'draft' ? $invoices->unpricedItems($invoice) : ['charges' => collect(), 'exceptions' => collect()];

        return view('billing::invoices.show', [
            'invoice' => $invoice,
            'groups' => $invoices->groupedLines($invoice),
            'creditNoteApproved' => $noteIds->mapWithKeys(fn ($id) => [$id => $approvals->isApproved('credit_note', 'credit_note', $id)])->all(),
            'creditNotePending' => $pending,
            'creditedByLine' => $invoice->status === 'draft' ? [] : $this->creditedByLine($invoice),
            'creditLine' => $invoice->lines->contains('id', $creditLine) ? $creditLine : 0,
            'newCharges' => $invoice->status === 'draft' && $invoice->invoice_type !== 'storage' ? $this->newChargesByJob($invoice) : collect(),
            'unpricedCharges' => $unpriced['charges'], 'unpricedExceptions' => $unpriced['exceptions'],
            'issuedWithUnpricedOverride' => $invoice->status !== 'draft' && Activity::query()->where('log_name', 'invoice')->where('description', 'issued_with_unpriced_override')
                ->where('subject_type', $invoice->getMorphClass())->where('subject_id', $invoice->id)->exists(),
        ]);
    }

    /**
     * Audit 2026-09-22 FIN-10 (CR #140): service charges of this draft's Job(s) that arrived after the draft was made and still sit in the
     * pool — the page lists them per Job with 并入本草稿 (InvoiceService::appendJobCharges).
     *
     * @return Collection<int, array{job: Job, count: int, amount_cents: int}> keyed by job id
     */
    private function newChargesByJob(Invoice $invoice): Collection
    {
        return Charge::query()->with('job')->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id')->whereIn('job_id', $invoice->jobs->pluck('id'))
            ->whereHas('chargeCode', fn ($q) => $q->where('category', '!=', 'storage'))->get()->groupBy('job_id')
            ->map(fn (Collection $group) => ['job' => $group->first()->job, 'count' => $group->count(), 'amount_cents' => (int) $group->sum('amount_cents')]);
    }

    /** 开出发票 — blocked while unpriced items sit in the draft's scope unless `unpriced_override` is ticked (CR #142; InvoiceService::issue). */
    public function issue(Request $request, Invoice $invoice, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate(['unpriced_override' => ['nullable', 'boolean']]);
        try {
            $invoices->issue($invoice, (bool) ($data['unpriced_override'] ?? false), $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => RuleViolation::display($e)]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.issued', ['no' => $invoice->fresh()->invoice_no]));
    }

    /** 移出草稿 (CR #142): one line back to the unbilled pool; refused on anything but a draft. */
    public function removeLine(Invoice $invoice, InvoiceLine $line, InvoiceService $invoices): RedirectResponse
    {
        abort_unless((int) $line->invoice_id === (int) $invoice->id, 404);
        try {
            $charge = $invoices->removeLine($invoice, $line);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => RuleViolation::display($e)]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.line_removed', ['code' => $line->charge_code, 'id' => $charge->id]));
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

    /** 作废收款 (audit 2026-09-22 FIN-13, CR #140): reason required; an offsetting negative row, never a delete. */
    public function voidPayment(Request $request, Payment $payment, InvoiceService $invoices): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        try {
            $invoices->voidPayment($payment, $data['reason'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['payment' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('billing.invoices.payment_voided', ['id' => $payment->id]));
    }

    /** 并入该草稿 / 并入本草稿 (audit 2026-09-22 FIN-10, CR #140): the Job's new service charges join the existing draft instead of a second invoice. */
    public function appendJob(Invoice $invoice, Job $job, InvoiceService $invoices): RedirectResponse
    {
        try {
            $added = $invoices->appendJobCharges($invoice, $job->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => RuleViolation::display($e)]);
        }

        return redirect()->route('billing.invoices.show', $invoice)->with('status', __('billing.invoices.appended', ['n' => $added, 'no' => $invoice->invoice_no]));
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
        $credited = $this->creditedByLine($invoice);
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

    /**
     * Ex-GST cents already credited per invoice line by every draft / issued credit note (the cap for a new line, and what the credit-note
     * form pre-fills as the remaining amount — FIN-16).
     *
     * @return array<int, int> invoice line id → credited cents
     */
    private function creditedByLine(Invoice $invoice): array
    {
        return DB::table('credit_note_lines')->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->whereIn('credit_note_lines.invoice_line_id', $invoice->lines()->pluck('id'))->where('credit_notes.status', '!=', 'cancelled')
            ->selectRaw('credit_note_lines.invoice_line_id, SUM(credit_note_lines.amount_cents) as credited')->groupBy('credit_note_lines.invoice_line_id')
            ->pluck('credited', 'invoice_line_id')->map(fn ($v) => (int) $v)->all();
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
