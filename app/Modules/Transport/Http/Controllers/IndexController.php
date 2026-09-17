<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentMarginService;
use App\Support\Auth\RequiredRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class IndexController extends Controller
{
    public function __invoke(ShipmentMarginService $margins): View
    {
        RequiredRoles::requireAny(ShipmentController::VIEWER_ROLES); // CHANGE_REQUESTS #130: the board shows cost / margin — not for the driver
        $shipments = Shipment::query()
            ->with(['job', 'carrier', 'selectedQuote', 'carrierCost'])
            ->withCount('quotes')
            ->latest('id')
            ->paginate(25);

        $asnIds = $shipments->getCollection()->pluck('asn_id')->filter()->unique()->values()->all();

        return view('transport::index', [
            'shipments' => $shipments,
            // 我方上门提货 (CHANGE_REQUESTS #124): a collection shows its 预报单 number where an order shipment has none to show.
            'asnNos' => $asnIds === [] ? [] : DB::table('asns')->whereIn('id', $asnIds)->pluck('asn_no', 'id')->all(),
            'margins' => $shipments->getCollection()->mapWithKeys(
                fn (Shipment $shipment): array => [$shipment->id => $margins->shipment($shipment)]
            ),
        ]);
    }
}
