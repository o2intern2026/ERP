<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\RedeliveryService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedeliveryController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, RedeliveryService $service): RedirectResponse
    {
        RequiredRoles::requireAny(['admin', 'customer_service', 'dispatcher']); // CHANGE_REQUESTS #130: the dispatcher plans, the driver executes

        try {
            $redelivery = $service->create($shipment, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['redelivery' => $exception->getMessage()]);
        }

        // CHANGE_REQUESTS #135 (audit TMS-10): the fee is a separate manual step — the new page says so and links the original's form.
        return redirect()
            ->route('transport.shipments.show', $redelivery)
            ->with('status', __('transport.redelivery.created'))
            ->with('redelivery_hint', $shipment->id);
    }
}
