<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\CarrierCostService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OwnFleetCostController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, CarrierCostService $costs): RedirectResponse
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'dispatcher', 'transport_operator', 'finance']), 403);
        $data = $request->validate([
            'cost_cents' => ['required', 'integer', 'min:0'],
            'note' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $costs->recordOwnFleet($shipment, (int) $data['cost_cents'], $data['note']);
        } catch (DomainException $exception) {
            return back()->withErrors(['cost_cents' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.costs.saved'));
    }
}
