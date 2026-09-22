<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Http\TransportValidation;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\CarrierPodService;
use App\Support\Auth\RequiredRoles;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CarrierPodController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, CarrierPodService $service): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher']); // CHANGE_REQUESTS #130: the dispatcher plans, the driver executes
        // CHANGE_REQUESTS #135 (audit TMS-12): the carrier's 实际签收时间 (<x-date-field time>: `Y-m-d\TH:i`, or the date alone while the
        // time box is empty) becomes delivered_at and the event time; JPG / PNG photos are accepted next to PDF.
        $validated = $request->validate([
            'recipient_name' => ['required', 'string', 'max:150'],
            'delivered_at' => ['required', 'date', 'before_or_equal:now'],
            'pod_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], TransportValidation::messages(), TransportValidation::attributes());

        try {
            $service->capture(
                $shipment,
                $request->user(),
                $validated['recipient_name'],
                $validated['pod_file'],
                CarbonImmutable::parse($validated['delivered_at'], config('app.timezone')),
            );
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['pod_file' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.carrier_pod.saved'));
    }
}
