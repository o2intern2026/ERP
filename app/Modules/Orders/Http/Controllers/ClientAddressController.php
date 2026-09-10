<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Client;
use App\Modules\Orders\Models\ClientAddress;
use App\Modules\Orders\OrderEnums;
use App\Support\Auth\RequiredRoles;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClientAddressController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['client_id' => ['nullable', 'integer']]);

        return view('orders::addresses.index', [
            'addresses' => ClientAddress::query()->with('client')
                ->when($filters['client_id'] ?? null, fn ($query, $clientId) => $query->where('client_id', $clientId))
                ->orderByDesc('usage_count')
                ->orderByDesc('last_used_at')
                ->orderBy('label')
                ->paginate(50)
                ->withQueryString(),
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        $this->authorizeMaintenance();

        return $this->form(new ClientAddress);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeMaintenance();
        ClientAddress::query()->create($this->validated($request));

        return redirect()->route('orders.addresses.index')->with('status', __('orders.addresses.messages.created'));
    }

    public function edit(ClientAddress $address): View
    {
        $this->authorizeMaintenance();

        return $this->form($address);
    }

    public function update(Request $request, ClientAddress $address): RedirectResponse
    {
        $this->authorizeMaintenance();
        $address->update($this->validated($request, $address));

        return redirect()->route('orders.addresses.index')->with('status', __('orders.addresses.messages.updated'));
    }

    private function form(ClientAddress $address): View
    {
        return view('orders::addresses.form', [
            'address' => $address,
            'clients' => Client::query()->where('status', 'active')->orderBy('name')->get(['id', 'code', 'name']),
            'states' => Enums::STATES,
            'addressTypes' => OrderEnums::ADDRESS_TYPES,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ClientAddress $address = null): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('clients', 'id')->where('status', 'active')],
            'label' => [
                'required', 'string', 'max:255',
                Rule::unique('client_addresses', 'label')
                    ->where(fn ($query) => $query->where('client_id', $request->integer('client_id')))
                    ->ignore($address?->id),
            ],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:255'],
            'suburb' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::in(Enums::STATES)],
            'postcode' => ['required', 'string', 'max:10'],
            'address_type' => ['required', Rule::in(OrderEnums::ADDRESS_TYPES)],
            'default_instructions' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function authorizeMaintenance(): void
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher']);
    }
}
