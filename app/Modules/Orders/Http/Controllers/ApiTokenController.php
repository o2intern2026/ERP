<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\OrderApiToken;
use App\Modules\Orders\Services\OrderApiTokenService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A4b: admin issues / revokes client API tokens; the plain token is shown once, only its hash is kept. */
final class ApiTokenController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        return view('orders::api-tokens.index', [
            'tokens' => OrderApiToken::query()->with(['client', 'creator'])->latest('id')->get(),
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'endpoint' => route('orders.api.orders.store'),
        ]);
    }

    public function store(Request $request, OrderApiTokenService $tokens): RedirectResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $issued = $tokens->issue((int) $data['client_id'], $data['name'], $request->user()->id);

        return redirect()->route('orders.api-tokens.index')
            ->with('status', __('orders.api.messages.issued', ['name' => $issued['token']->name]))
            ->with('plain_token', $issued['plain']);
    }

    public function revoke(OrderApiToken $token, OrderApiTokenService $tokens): RedirectResponse
    {
        $this->authorizeAdmin();
        $tokens->revoke($token);

        return redirect()->route('orders.api-tokens.index')->with('status', __('orders.api.messages.revoked'));
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);
    }
}
