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

        $charges = Charge::query()->with(['chargeCode', 'client', 'job'])
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
            'reviewCount' => Charge::query()->where('status', 'needs_review')->count(),
        ]);
    }

    public function review(): View
    {
        return view('billing::charges.review', ['charges' => Charge::query()->with(['chargeCode', 'client', 'job'])->where('status', 'needs_review')->orderBy('id')->paginate(50)]);
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

    public function manual(): View
    {
        return view('billing::charges.manual', [
            'codes' => ChargeCode::query()->where('active', true)->orderBy('code')->get(),
            'jobs' => Job::query()->with('client')->orderByDesc('id')->limit(300)->get(),
        ]);
    }

    public function storeManual(Request $request, ChargeEngine $engine): RedirectResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', Rule::exists('jobs', 'id')], 'charge_code' => ['required', Rule::exists('charge_codes', 'code')],
            'qty' => ['required', 'numeric', 'gt:0'], 'amount' => ['nullable', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:255'],
        ]);
        $job = Job::query()->findOrFail($data['job_id']);
        $charge = $engine->manual($job->id, $job->client_id, $data['charge_code'], (float) $data['qty'], $data['reason'], isset($data['amount']) && $data['amount'] !== '' ? (int) round($data['amount'] * 100) : null);

        return redirect()->route('billing.index')->with('status', __('billing.charges.manual_added', ['id' => $charge->id, 'status' => __('billing.charge_statuses.'.$charge->status)]));
    }

    public function reverse(Request $request, Charge $charge, ChargeEngine $engine): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $twin = $engine->reverse($charge, $data['reason']);

        return back()->with('status', $twin ? __('billing.charges.reversed', ['id' => $charge->id]) : __('billing.charges.not_reversible'));
    }
}
