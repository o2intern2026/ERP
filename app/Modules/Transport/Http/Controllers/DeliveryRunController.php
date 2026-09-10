<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeliveryRunController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeCoordinator($request);

        return view('transport::runs.index', [
            'runs' => DeliveryRun::query()
                ->with('driver')
                ->withCount('stops')
                ->orderByDesc('run_date')
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeCoordinator($request);

        return view('transport::runs.create', [
            'drivers' => User::query()
                ->role('transport_operator')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, DeliveryRunService $service): RedirectResponse
    {
        $this->authorizeCoordinator($request);
        $validated = $request->validate([
            'run_date' => ['required', 'date_format:Y-m-d'],
            'driver_id' => ['required', 'integer', 'exists:users,id'],
            'vehicle' => ['required', 'string', 'max:100'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $run = $service->create(
                $validated['run_date'],
                (int) $validated['driver_id'],
                $validated['vehicle'],
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['driver_id' => $exception->getMessage()]);
        }

        return redirect()
            ->route('transport.runs.show', $run)
            ->with('status', __('transport.runs.created'));
    }

    public function show(Request $request, DeliveryRun $deliveryRun): View
    {
        $this->authorizeCoordinator($request);

        return view('transport::runs.show', [
            'run' => $deliveryRun->load(['driver', 'stops.shipment.client']),
            'eligibleShipments' => Shipment::query()
                ->with(['client', 'selectedQuote'])
                ->whereNull('delivery_run_id')
                ->where('shipment_type', 'outbound')
                ->whereIn('status', ['quote_confirmed', 'booked'])
                ->whereHas('selectedQuote', fn ($query) => $query
                    ->where('source', 'own_fleet')
                    ->where('quote_stage', 'final')
                    ->where('status', 'selected'))
                ->orderBy('shipment_no')
                ->get(),
        ]);
    }

    private function authorizeCoordinator(Request $request): void
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher', 'transport_operator']);
    }
}
