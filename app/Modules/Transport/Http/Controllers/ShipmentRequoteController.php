<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentRequoteService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** 重新报价 (CHANGE_REQUESTS #133, audit TMS-02): a planner asks for fresh quotes once the 24 h ones are dead. */
class ShipmentRequoteController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ShipmentRequoteService $requotes): RedirectResponse
    {
        RequiredRoles::requireAny(DeliveryRunController::PLANNER_ROLES); // admin | customer_service | dispatcher — the driver executes (#130)

        try {
            $result = $requotes->requote($shipment, $request->user());
        } catch (DomainException $exception) {
            return back()->withErrors(['requote' => $exception->getMessage()]);
        }

        if ($result['count'] === 0) {
            // No automatic option: TransportOptionService raised the manual_transport exception; the page still offers 人工报价.
            return back()->withErrors(['requote' => __('transport.quotes.requote_none')]);
        }

        return back()->with('status', __('transport.quotes.requoted', [
            'count' => $result['count'],
            'stage' => __('transport.quote_stages.'.$result['stage']),
        ]));
    }
}
