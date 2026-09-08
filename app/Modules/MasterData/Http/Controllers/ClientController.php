<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A2: clients — billing behaviour (payment_terms, invoice_mode, markup, cut-off, standard card) lives here. */
class ClientController extends Controller
{
    public function index(): View
    {
        return view('masterdata::clients.index', ['clients' => Client::query()->orderBy('name')->paginate(50)]);
    }

    public function create(): View
    {
        return view('masterdata::clients.form', ['client' => new Client, 'states' => Enums::STATES]);
    }

    public function store(Request $request): RedirectResponse
    {
        Client::query()->create($this->validated($request));

        return redirect()->route('masterdata.index')->with('status', __('masterdata.saved'));
    }

    public function edit(Client $client): View
    {
        return view('masterdata::clients.form', ['client' => $client, 'states' => Enums::STATES]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($this->validated($request, $client));

        return redirect()->route('masterdata.index')->with('status', __('masterdata.saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Client $client = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('clients', 'code')->ignore($client?->id)],
            'name' => ['required', 'string', 'max:255'],
            'abn' => ['nullable', 'string', 'max:20'],
            'leg_type' => ['required', Rule::in(Enums::LEG_TYPES)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(Enums::STATES)],
            'postcode' => ['nullable', 'string', 'max:10'],
            'status' => ['required', Rule::in(Enums::MASTER_STATUSES)],
            'payment_terms' => ['required', 'string', 'regex:'.Enums::PAYMENT_TERMS_PATTERN],
            'invoice_mode' => ['required', Rule::in(Enums::INVOICE_MODES)],
            'invoice_period' => ['nullable', Rule::in(Enums::INVOICE_PERIODS)],
            'invoice_grouping' => ['nullable', Rule::in(Enums::INVOICE_GROUPINGS)],
            'default_markup_percent' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'dispatch_cutoff_time' => ['nullable', 'date_format:H:i'],
        ]);

        // Invoice cadence / grouping have sensible defaults so older forms and imports keep working (tester feedback #4).
        $data['invoice_period'] = $data['invoice_period'] ?? $client?->invoice_period ?? 'monthly';
        $data['invoice_grouping'] = $data['invoice_grouping'] ?? $client?->invoice_grouping ?? 'job';

        return $data;
    }
}
