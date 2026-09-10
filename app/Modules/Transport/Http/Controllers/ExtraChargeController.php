<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ExtraChargeService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExtraChargeController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ExtraChargeService $service): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher', 'transport_operator']);
        $validated = $request->validate([
            'charge_type' => ['required', 'string', Rule::in(ExtraChargeService::CHARGE_TYPES)],
            'qty' => ['required', 'numeric', 'gt:0'],
            'uom' => ['required', 'string', Rule::in(ExtraChargeService::UOMS)],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'note' => ['required', 'string', 'max:1000'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $service->report(
                $shipment,
                $validated['charge_type'],
                (float) $validated['qty'],
                $validated['uom'],
                isset($validated['cost_cents']) ? (int) $validated['cost_cents'] : null,
                $validated['note'],
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['extra_charge' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.extra_charges.reported'));
    }
}
