<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MasterData\Models\Carrier;
use App\Support\Enums;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Carrier master data (code, name, ABN, contact). Transport attributes are carrier_services in the Transport module (X2). */
class CarrierController extends Controller
{
    public function index(): View
    {
        return view('masterdata::carriers.index', ['carriers' => Carrier::query()->orderBy('name')->paginate(50)]);
    }

    public function create(): View
    {
        return view('masterdata::carriers.form', ['carrier' => new Carrier]);
    }

    public function store(Request $request): RedirectResponse
    {
        Carrier::query()->create($this->validated($request));

        return redirect()->route('masterdata.carriers.index')->with('status', __('masterdata.saved'));
    }

    public function edit(Carrier $carrier): View
    {
        return view('masterdata::carriers.form', ['carrier' => $carrier]);
    }

    public function update(Request $request, Carrier $carrier): RedirectResponse
    {
        $carrier->update($this->validated($request, $carrier));

        return redirect()->route('masterdata.carriers.index')->with('status', __('masterdata.saved'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Carrier $carrier = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('carriers', 'code')->ignore($carrier?->id)],
            'name' => ['required', 'string', 'max:255'],
            'abn' => ['nullable', 'string', 'max:20'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'status' => ['required', Rule::in(Enums::MASTER_STATUSES)],
        ]);
    }
}
