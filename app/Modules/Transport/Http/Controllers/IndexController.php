<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentBulkActionService;
use App\Modules\Transport\Services\ShipmentMarginService;
use App\Modules\Transport\Support\TransportEnums;
use App\Support\Auth\RequiredRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IndexController extends Controller
{
    public function __invoke(Request $request, ShipmentMarginService $margins, ShipmentBulkActionService $bulk): View
    {
        RequiredRoles::requireAny(ShipmentController::VIEWER_ROLES); // CHANGE_REQUESTS #130: the board shows cost / margin — not for the driver
        $statuses = array_merge(TransportEnums::OUTBOUND_STATUSES, TransportEnums::RETURN_STATUSES);
        // CHANGE_REQUESTS #160 / #161: a status filter and a 25 / 100 / 300 page so 全选本页 can cover a whole batch.
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($statuses)],
            'per_page' => ['nullable', Rule::in(['25', '100', '300'])],
        ]);
        $shipments = Shipment::query()
            ->with(['job', 'carrier', 'selectedQuote', 'carrierCost', 'quotes.carrier'])
            ->withCount('quotes')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        $asnIds = $shipments->getCollection()->pluck('asn_id')->filter()->unique()->values()->all();
        $planner = $request->user()->hasAnyRole(BulkActionController::ROLES);

        return view('transport::index', [
            'shipments' => $shipments,
            'filters' => $filters,
            'statuses' => $statuses,
            // 我方上门提货 (CHANGE_REQUESTS #124): a collection shows its 预报单 number where an order shipment has none to show.
            'asnNos' => $asnIds === [] ? [] : DB::table('asns')->whereIn('id', $asnIds)->pluck('asn_no', 'id')->all(),
            'margins' => $shipments->getCollection()->mapWithKeys(
                fn (Shipment $shipment): array => [$shipment->id => $margins->shipment($shipment)]
            ),
            'planner' => $planner,
            // shipment id => ['quote' => the final quote 批量确认 would confirm, 'basis' => client | recommended]; only planners see the boxes.
            'confirmable' => $planner
                ? $shipments->getCollection()->mapWithKeys(fn (Shipment $shipment): array => [$shipment->id => $bulk->confirmable($shipment)])->filter()
                : collect(),
            'bookable' => $planner
                ? $shipments->getCollection()->filter(fn (Shipment $shipment): bool => $bulk->bookable($shipment))->keyBy('id')
                : collect(),
        ]);
    }
}
