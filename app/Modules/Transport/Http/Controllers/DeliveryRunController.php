<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\DeliveryRunService;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeliveryRunController extends Controller
{
    /** CHANGE_REQUESTS #130 (lead 2026-09-17): the dispatcher plans, the driver executes — only these roles create runs and edit stops. */
    public const PLANNER_ROLES = ['admin', 'customer_service', 'dispatcher'];

    /** Drivers (transport_operator) may still open the run list and a run page, limited to runs assigned to them. */
    public const READER_ROLES = ['admin', 'customer_service', 'dispatcher', 'transport_operator'];

    public function index(Request $request): View
    {
        RequiredRoles::requireAny(self::READER_ROLES);

        return view('transport::runs.index', [
            'runs' => DeliveryRun::query()
                ->with('driver')
                ->withCount('stops')
                ->when(self::driverOnly($request), fn ($query) => $query->where('driver_id', $request->user()->id))
                ->orderByDesc('run_date')
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function create(Request $request): View
    {
        RequiredRoles::requireAny(self::PLANNER_ROLES);

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
        RequiredRoles::requireAny(self::PLANNER_ROLES);
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
        RequiredRoles::requireAny(self::READER_ROLES);
        // Another driver's run is not revealed — 404, not 403 (#130).
        abort_if(self::driverOnly($request) && (int) $deliveryRun->driver_id !== (int) $request->user()->id, 404);

        return view('transport::runs.show', [
            'run' => $deliveryRun->load(['driver', 'stops.shipment.client']),
            'eligibleShipments' => self::driverOnly($request) ? new Collection : Shipment::query()
                ->with(['client', 'selectedQuote'])
                ->whereNull('delivery_run_id')
                ->whereIn('shipment_type', TransportEnums::DELIVERY_TYPES) // outbound deliveries and inbound collections (#124)
                ->whereIn('status', ['quote_confirmed', 'booked'])
                ->whereHas('selectedQuote', fn ($query) => $query
                    ->where('source', 'own_fleet')
                    ->where('quote_stage', 'final')
                    ->where('status', 'selected'))
                ->orderBy('shipment_no')
                ->get(),
        ]);
    }

    /** A signed-in user who may read runs but not plan them, i.e. a driver without any planner role. */
    public static function driverOnly(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && ! $user->hasAnyRole(self::PLANNER_ROLES);
    }
}
