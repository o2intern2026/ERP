<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\RedeliveryService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedeliveryController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, RedeliveryService $service): RedirectResponse
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']),
            403,
        );

        try {
            $redelivery = $service->create($shipment, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['redelivery' => $exception->getMessage()]);
        }

        return redirect()
            ->route('transport.shipments.show', $redelivery)
            ->with('status', __('transport.redelivery.created'));
    }
}
