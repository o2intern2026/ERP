<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\ChargeCode;
use App\Modules\Billing\Models\CustomerQuote;
use App\Modules\Billing\Services\QuoteService;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A18 / FIN-6: quote a one-off job from charge codes; POA lines stay flagged until Finance prices them. */
class QuoteController extends Controller
{
    public function index(): View
    {
        return view('billing::quotes.index', ['quotes' => CustomerQuote::query()->with('client')->orderByDesc('id')->paginate(50)]);
    }

    public function create(): View
    {
        return view('billing::quotes.create', ['clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']), 'codes' => ChargeCode::query()->where('active', true)->orderBy('code')->get()]);
    }

    public function store(Request $request, QuoteService $quotes): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')], 'stage' => ['required', Rule::in(Enums::QUOTE_STAGES)], 'valid_until' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.charge_code' => ['nullable', 'string'], 'lines.*.qty' => ['nullable', 'numeric', 'min:0'], 'lines.*.weight_kg' => ['nullable', 'numeric', 'min:0'], 'lines.*.cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $lines = collect($data['lines'])->filter(fn ($l) => ! empty($l['charge_code']) && (float) ($l['qty'] ?? 0) > 0)
            ->map(fn ($l) => ['charge_code' => $l['charge_code'], 'qty' => (float) $l['qty'], 'context' => array_filter(['weight_kg' => $l['weight_kg'] ?? null, 'cost_cents' => isset($l['cost']) && $l['cost'] !== '' ? (int) round($l['cost'] * 100) : null], fn ($v) => $v !== null)])->values()->all();
        if ($lines === []) {
            return back()->withErrors(['lines' => __('billing.quotes.no_lines')])->withInput();
        }

        $quote = $quotes->create((int) $data['client_id'], $lines, ['stage' => $data['stage'], 'valid_until' => $data['valid_until'] ?? null, 'notes' => $data['notes'] ?? null]);

        return redirect()->route('billing.quotes.show', $quote)->with('status', __('billing.quotes.created', ['no' => $quote->quote_no]));
    }

    public function show(CustomerQuote $quote): View
    {
        return view('billing::quotes.show', ['quote' => $quote->load(['client', 'lines']), 'statuses' => Enums::QUOTE_STATUSES]);
    }

    public function status(Request $request, CustomerQuote $quote, QuoteService $quotes): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Enums::QUOTE_STATUSES)]]);
        $quotes->setStatus($quote, $data['status']);

        return back()->with('status', __('billing.quotes.status_saved'));
    }
}
