<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ExtraChargeService;
use App\Support\Auth\RequiredRoles;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExtraChargeController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ExtraChargeService $service): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher']); // CHANGE_REQUESTS #130: the dispatcher plans, the driver executes
        // CHANGE_REQUESTS #135 (audit TMS-03 / TMS-11): the carrier cost is typed in dollars; a repeat of an already reported type
        // needs the explicit 确认再次上报 tick; the report is recorded on the shipment next to its event.
        $validated = $request->validate([
            'charge_type' => ['required', 'string', Rule::in(ExtraChargeService::CHARGE_TYPES)],
            'qty' => ['required', 'numeric', 'gt:0'],
            'uom' => ['required', 'string', Rule::in(ExtraChargeService::UOMS)],
            'carrier_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'note' => ['required', 'string', 'max:1000'],
            'confirm_repeat' => ['nullable', 'boolean'],
        ], TransportValidation::messages(), TransportValidation::attributes());
        $cost = isset($validated['carrier_cost']) && $validated['carrier_cost'] !== '' ? Money::fromDecimal($validated['carrier_cost']) : null;

        try {
            $record = $service->report(
                $shipment,
                $validated['charge_type'],
                (float) $validated['qty'],
                $validated['uom'],
                $cost?->cents,
                $validated['note'],
                $request->user(),
                (bool) ($validated['confirm_repeat'] ?? false),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['extra_charge' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.extra_charges.reported_detail', [
            'type' => __('transport.extra_charges.types.'.$record->charge_type),
            'qty' => rtrim(rtrim(number_format((float) $record->qty, 2, '.', ''), '0'), '.'),
            'uom' => __('transport.extra_charges.uoms.'.$record->uom),
            'cost' => $cost === null ? '' : __('transport.extra_charges.cost_suffix', ['amount' => $cost->format()]),
        ]));
    }
}
