<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\DeliveryRunService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RunStopController extends Controller
{
    public function store(Request $request, DeliveryRun $deliveryRun, DeliveryRunService $service): RedirectResponse
    {
        $this->authorizeCoordinator($request);
        $validated = $request->validate([
            'shipment_id' => ['required', 'integer', 'exists:shipments,id'],
            'eta' => ['nullable', 'date'],
        ]);

        try {
            $service->addShipment(
                $deliveryRun,
                Shipment::query()->findOrFail($validated['shipment_id']),
                $validated['eta'] ?? null,
            );
        } catch (DomainException $exception) {
            return back()->withErrors(['shipment_id' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.runs.shipment_added'));
    }

    public function reorder(Request $request, DeliveryRun $deliveryRun, DeliveryRunService $service): RedirectResponse
    {
        $this->authorizeCoordinator($request);
        $validated = $request->validate([
            'positions' => ['required', 'array'],
            'positions.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $positions = $validated['positions'];
        asort($positions, SORT_NUMERIC);

        try {
            $service->reorder($deliveryRun, array_map('intval', array_keys($positions)));
        } catch (DomainException $exception) {
            return back()->withErrors(['positions' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.runs.order_saved'));
    }

    private function authorizeCoordinator(Request $request): void
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']),
            403,
        );
    }
}
