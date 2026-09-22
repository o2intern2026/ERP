<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\Charge;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Services\ChargeEngine;
use App\Modules\MasterData\Models\Client;
use App\Modules\Platform\Models\Job;
use App\Support\Enums;
use App\Support\Exceptions\RuleViolation;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** FIN-2 charge list (every line points at its source), FIN-5 manual charges, POA review queue, reversals. */
class ChargeController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'], 'job_no' => ['nullable', 'string', 'max:30'], 'code' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(Enums::CHARGE_STATUSES)], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);

        $charges = Charge::query()->with(['chargeCode', 'client', 'job', 'invoiceLine.invoice'])
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['job_no'] ?? null, fn ($q, $v) => $q->whereHas('job', fn ($j) => $j->where('job_no', 'like', "%{$v}%")))
            ->when($filters['code'] ?? null, fn ($q, $v) => $q->whereHas('chargeCode', fn ($c) => $c->where('code', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('charge_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('charge_date', '<=', $v))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('billing::charges.index', [
            'charges' => $charges, 'filters' => $filters,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']), 'statuses' => Enums::CHARGE_STATUSES,
            'counts' => Charge::query()->selectRaw('status, count(*) as n, sum(amount_cents) as amount')->groupBy('status')->get()->keyBy('status'),
            // Audit 2026-09-22 FIN-10 (CR #140): 待开票 is exactly what the unbilled pool lists (pending / approved, not yet on a draft); rows sitting on a draft are counted apart.
            'poolCount' => Charge::query()->whereIn('status', ['pending', 'approved'])->whereNull('invoice_line_id')->count(),
            'draftedCount' => Charge::query()->whereIn('status', ['pending', 'approved'])->whereNotNull('invoice_line_id')->count(),
            'reviewCount' => Charge::query()->where('status', 'needs_review')->count(),
        ]);
    }

    public function review(Request $request): View
    {
        // CR #132: the unbilled pool links here per client (待复核 N); missing-rate rows pre-fill the suggested amount in the view.
        $filters = $request->validate(['client_id' => ['nullable', 'integer']]);
        $client = ($filters['client_id'] ?? null) ? Client::query()->find($filters['client_id']) : null;

        return view('billing::charges.review', [
            'charges' => Charge::query()->with(['chargeCode', 'client', 'job'])->where('status', 'needs_review')->when($client, fn ($q) => $q->where('client_id', $client->id))->orderBy('id')->paginate(50)->withQueryString(),
            'client' => $client,
        ]);
    }

    public function storeReview(Request $request, Charge $charge, ChargeEngine $engine): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0'], 'note' => ['required', 'string', 'max:255']]);
        try {
            $engine->review($charge, (int) round($data['amount'] * 100), $data['note']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => RuleViolation::display($e)]);
        }

        return back()->with('status', __('billing.charges.reviewed'));
    }

    /** Audit 2026-09-22 FIN-09 (CR #140): `?job_id=` (from the Job page's 手工加费 link) pre-selects the Job, `?client_id=` narrows the Job list to that client. */
    public function manual(Request $request): View
    {
        $prefill = $request->validate(['job_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer']]);
        $client = ($prefill['client_id'] ?? null) ? Client::query()->find($prefill['client_id']) : null;
        $jobs = Job::query()->with('client')->when($client, fn ($q) => $q->where('client_id', $client->id))->orderByDesc('id')->limit(300)->get();
        $job = ($prefill['job_id'] ?? null) ? Job::query()->with('client')->find($prefill['job_id']) : null;
        if ($job !== null && ! $jobs->contains('id', $job->id)) {
            $jobs->prepend($job); // an older Job than the latest 300 is still chargeable from its own page
        }

        return view('billing::charges.manual', [
            'codes' => ChargeCode::query()->where('active', true)->orderBy('code')->get(),
            'jobs' => $jobs, 'prefillJobId' => $job?->id, 'client' => $client,
        ]);
    }

    public function storeManual(Request $request, ChargeEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', Rule::exists('jobs', 'id')], 'charge_code' => ['required', Rule::exists('charge_codes', 'code')],
            'qty' => ['required', 'numeric', 'gt:0'], 'amount' => ['nullable', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:255'],
            'charge_date' => ['nullable', 'date', 'before_or_equal:today'], // FIN-09: the day the fee belongs to, never in the future; today when omitted
        ]);
        $job = Job::query()->findOrFail($data['job_id']);
        $charge = $engine->manual($job->id, $job->client_id, $data['charge_code'], (float) $data['qty'], $data['reason'], isset($data['amount']) && $data['amount'] !== '' ? (int) round($data['amount'] * 100) : null, null, filled($data['charge_date'] ?? null) ? Carbon::parse($data['charge_date']) : null);

        return redirect()->route('billing.index')->with('status', __('billing.charges.manual_added', ['id' => $charge->id, 'status' => __('billing.charge_statuses.'.$charge->status)]));
    }

    public function reverse(Request $request, Charge $charge, ChargeEngine $engine): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        // Audit 2026-09-22 FIN-16 (CR #140): a charge on an issued invoice is reduced by a credit note on that invoice — a manual 冲销 would
        // create a billable negative twin on top of it and refund the client twice. A charge already on a draft is handled on the draft.
        if ($charge->invoice_line_id !== null || $charge->status === 'invoiced') {
            $invoiceNo = $charge->invoiceLine?->invoice?->invoice_no ?? '—';

            return back()->withErrors(['charge' => __('billing.charges.errors.'.($charge->status === 'invoiced' ? 'invoiced_use_credit_note' : 'on_draft'), ['id' => $charge->id, 'no' => $invoiceNo])]);
        }
        $before = $charge->status;
        $twin = $engine->reverse($charge, $data['reason']);
        $reversed = $twin !== null || ($before !== 'reversed' && $charge->fresh()->status === 'reversed'); // a $0 missing-rate placeholder is reversed without a twin (CR #132)

        return back()->with('status', $reversed ? __('billing.charges.reversed', ['id' => $charge->id]) : __('billing.charges.not_reversible'));
    }
}
