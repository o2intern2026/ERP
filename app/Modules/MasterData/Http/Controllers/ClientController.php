<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Billing\Models\RateCard;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A2: clients — billing behaviour (payment_terms, invoice_mode, markup, cut-off, standard card) lives here.
 * CHANGE_REQUESTS #134 (audit A13 / GAP-01): there is no staff "new client" form any more — a company registers itself at
 * /register (Platform RegistrationController: pending client + inactive login, standard rate card bound), then staff approve,
 * edit and manage it here. Every code path that still creates a Client is covered by Client::creating (standard card).
 */
class ClientController extends Controller
{
    public function index(): View
    {
        return view('masterdata::clients.index', [
            'clients' => Client::query()->orderByRaw("case when status = 'pending' then 0 else 1 end")->orderBy('name')->paginate(50), // self-registered clients first (tester feedback #8)
            'pendingCount' => Client::query()->where('status', 'pending')->count(),
            'signupOpen' => (bool) config('erp.allow_signup'),
        ]);
    }

    public function edit(Client $client): View
    {
        return view('masterdata::clients.form', [
            'client' => $client,
            'states' => Enums::STATES,
            'standardCard' => $client->standardRateCard, // read-only: 标准价目表 name · version, or a warning + 修复 when null
            'ownCard' => $client->activeOwnRateCard(),   // 专属价目表 有 / 无
        ]);
    }

    /** #134 修复 (admin only, route middleware): bind the active standard card to a client that has none. */
    public function bindStandardCard(Client $client): RedirectResponse
    {
        $card = RateCard::activeStandard();
        if ($card === null) {
            return back()->withErrors(['standard_rate_card_id' => __('masterdata.clients.no_active_standard_card')]);
        }
        if ($client->standard_rate_card_id === null) {
            $client->update(['standard_rate_card_id' => $card->id]);
        }

        return back()->with('status', __('masterdata.clients.standard_card_bound', ['name' => $client->name, 'card' => $card->name.' · v'.$card->version]));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $data = $this->validated($request, $client);
        DB::transaction(function () use ($client, $data) {
            $client->update($data);
            if ($client->wasChanged('status') && $client->status === 'active') {
                User::query()->withoutGlobalScopes()->where('client_id', $client->id)->update(['is_active' => true]); // approving via the edit form behaves like approve() (tester feedback #8)
            }
            // CR #137 (audit GAP-03): 停用 closes the portal — the client's logins go inactive in the same write (symmetrical to approve);
            // ClientScope also signs out anyone of that client already inside. Re-activating turns them back on above.
            if ($client->wasChanged('status') && $client->status === 'inactive') {
                User::query()->withoutGlobalScopes()->where('client_id', $client->id)->update(['is_active' => false]);
            }
        });

        return redirect()->route('masterdata.index')->with('status', __($client->status === 'inactive' && $client->wasChanged('status') ? 'masterdata.clients.deactivated' : 'masterdata.saved', ['name' => $client->name]));
    }

    /** Tester feedback #8: a self-registered (`pending`) client is approved here — the client and every user under it become active and can sign in. */
    public function approve(Client $client): RedirectResponse
    {
        DB::transaction(function () use ($client) {
            $client->update(['status' => 'active']);
            User::query()->withoutGlobalScopes()->where('client_id', $client->id)->update(['is_active' => true]);
        });

        return redirect()->route('masterdata.index')->with('status', __('masterdata.clients.approved', ['name' => $client->name]));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Client $client): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('clients', 'code')->ignore($client->id)],
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
            'status' => ['required', Rule::in(Enums::CLIENT_STATUSES)],
            'payment_terms' => ['required', 'string', 'regex:'.Enums::PAYMENT_TERMS_PATTERN],
            'invoice_mode' => ['required', Rule::in(Enums::INVOICE_MODES)],
            'invoice_period' => ['nullable', Rule::in(Enums::INVOICE_PERIODS)],
            'invoice_grouping' => ['nullable', Rule::in(Enums::INVOICE_GROUPINGS)],
            'default_markup_percent' => ['required', 'numeric', 'min:0', 'max:999.99'],
            'dispatch_cutoff_time' => ['nullable', 'date_format:H:i'],
        ]);

        // Invoice cadence / grouping have sensible defaults so older forms and imports keep working (tester feedback #4).
        $data['invoice_period'] = $data['invoice_period'] ?? $client->invoice_period ?? 'monthly';
        $data['invoice_grouping'] = $data['invoice_grouping'] ?? $client->invoice_grouping ?? 'job';

        return $data;
    }
}
