<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\CarrierPodService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CarrierPodController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, CarrierPodService $service): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']),
            403,
        );
        $validated = $request->validate([
            'recipient_name' => ['required', 'string', 'max:150'],
            'pod_file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            $service->capture($shipment, $request->user(), $validated['recipient_name'], $validated['pod_file']);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['pod_file' => $exception->getMessage()]);
        }

        return back()->with('status', __('transport.carrier_pod.saved'));
    }
}
