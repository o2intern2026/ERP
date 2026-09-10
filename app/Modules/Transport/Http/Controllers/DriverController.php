<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\DeliveryRun;
use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Services\DriverPodService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DriverController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeDriver();

        return view('transport::driver', [
            'runs' => DeliveryRun::query()
                ->with(['stops.shipment.client', 'stops.shipment.selectedQuote', 'stops.shipment.pods'])
                ->where('driver_id', $request->user()->id)
                ->whereDate('run_date', today())
                ->whereIn('status', ['planned', 'dispatched'])
                ->orderBy('run_no')
                ->get(),
            'failureReasons' => DriverPodService::FAILURE_REASONS,
        ]);
    }

    public function deliver(Request $request, RunStop $runStop, DriverPodService $service): RedirectResponse
    {
        $this->authorizeDriver();
        $this->authorizeStop($request, $runStop);
        $validated = $request->validate([
            'recipient_name' => ['required', 'string', 'max:150'],
            'signature_data' => ['required', 'string', 'max:3000000'],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $service->deliver(
                $runStop,
                $request->user(),
                $validated['recipient_name'],
                $validated['signature_data'],
                $validated['photos'],
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['pod' => $exception->getMessage()]);
        }

        return redirect()->route('transport.driver')->with('status', __('transport.driver.delivered'));
    }

    public function fail(Request $request, RunStop $runStop, DriverPodService $service): RedirectResponse
    {
        $this->authorizeDriver();
        $this->authorizeStop($request, $runStop);
        $validated = $request->validate([
            'failure_reason' => ['required', 'string', Rule::in(DriverPodService::FAILURE_REASONS)],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $service->fail($runStop, $request->user(), $validated['failure_reason']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['failure_reason' => $exception->getMessage()]);
        }

        return redirect()->route('transport.driver')->with('status', __('transport.driver.failed'));
    }

    /** The driver page lists the signed-in driver's own runs, so it is for transport operators only (nav is gated the same way). */
    private function authorizeDriver(): void
    {
        RequiredRoles::requireAny(['transport_operator']);
    }

    private function authorizeStop(Request $request, RunStop $stop): void
    {
        abort_unless(
            $stop->deliveryRun()->where('driver_id', $request->user()->id)->exists(),
            403,
            __('transport.driver.stop_unavailable'),
        );
    }
}
