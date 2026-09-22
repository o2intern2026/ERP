<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\CarrierCostService;
use App\Support\Auth\RequiredRoles;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OwnFleetCostController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, CarrierCostService $costs): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'dispatcher', 'finance']); // CHANGE_REQUESTS #130
        // CHANGE_REQUESTS #135 (audit TMS-03): dollars in, cents stored — the flash echoes what was understood.
        $data = $request->validate([
            'actual_cost' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'note' => ['required', 'string', 'max:1000'],
        ], TransportValidation::messages(), TransportValidation::attributes());
        $cost = Money::fromDecimal($data['actual_cost']);

        try {
            $costs->recordOwnFleet($shipment, $cost->cents, $data['note']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['actual_cost' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.costs.saved_amount', ['amount' => $cost->format()]));
    }
}
