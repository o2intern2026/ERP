<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Billing\Models\RateCard;
use App\Modules\MasterData\Models\Client;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Tester feedback #8: client self-registration. One form creates the Client (status `pending`, its own code, the standard rate
 * card, prepaid terms) and its first client-role user (inactive). Staff approve from /admin/clients (ClientController::approve);
 * until then LoginController refuses the account with a "pending" message. Data isolation is the existing ClientScope; invoices
 * already snapshot the client's name / ABN / address as Bill-to. Disabled with ALLOW_SIGNUP=false (config erp.allow_signup).
 */
class RegistrationController extends Controller
{
    public function show(): View
    {
        abort_unless(config('erp.allow_signup'), 404, __('platform.auth.signup_closed'));

        return view('platform::auth.register', ['states' => Enums::STATES]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('erp.allow_signup'), 404, __('platform.auth.signup_closed'));

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'abn' => ['nullable', 'string', 'max:20'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'suburb' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', Rule::in(Enums::STATES)],
            'postcode' => ['nullable', 'string', 'max:10'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $email = Str::lower(trim($data['email']));

        DB::transaction(function () use ($data, $email) {
            $client = Client::query()->withoutGlobalScopes()->create([
                'code' => $this->uniqueCode($data['company_name']),
                'name' => $data['company_name'],
                'abn' => $data['abn'] ?? null,
                'leg_type' => 'both',
                'contact_name' => $data['contact_name'],
                'contact_phone' => $data['contact_phone'],
                'contact_email' => $email,
                'billing_email' => $email,
                'address' => $data['address'] ?? null,
                'suburb' => $data['suburb'] ?? null,
                'state' => $data['state'] ?? null,
                'postcode' => $data['postcode'] ?? null,
                'status' => 'pending',
                'payment_terms' => 'prepaid', // staff relax terms after approval
                'invoice_mode' => 'per_job',
                'invoice_period' => 'monthly',
                'invoice_grouping' => 'job',
                'default_markup_percent' => 20,
                'standard_rate_card_id' => RateCard::query()->where('is_standard', true)->where('status', 'active')->orderByDesc('version')->value('id'),
            ]);

            $user = User::query()->create([
                'name' => $data['contact_name'],
                'email' => $email,
                'password' => $data['password'],
                'client_id' => $client->id,
                'is_active' => false, // LoginController refuses inactive users until ClientController::approve()
            ]);
            $user->syncRoles(['client']);
        });

        return redirect()->route('platform.login')->with('status', __('platform.auth.registered'));
    }

    /** Client code from the company name (A–Z 0–9, ≤ 12 chars), suffixed until unique. */
    private function uniqueCode(string $name): string
    {
        $base = Str::upper(Str::substr((string) preg_replace('/[^A-Za-z0-9]+/', '', Str::ascii($name)), 0, 12)) ?: 'CLIENT';
        $code = $base;
        for ($n = 2; Client::query()->withoutGlobalScopes()->where('code', $code)->exists(); $n++) {
            $code = $base.'-'.$n;
        }

        return $code;
    }
}
