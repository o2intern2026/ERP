<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentLabelService;
use App\Modules\Transport\Services\ShipmentMarginService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use App\Support\Auth\RequiredRoles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    /**
     * CHANGE_REQUESTS #130 (lead 2026-09-17): the shipment board, the shipment page and its 托运清单 show carrier cost, quotes and
     * margin — every staff role except the driver (transport_operator) keeps its read access; client users never reach /transport.
     */
    public const VIEWER_ROLES = ['admin', 'customer_service', 'dispatcher', 'warehouse_supervisor', 'warehouse_operator', 'finance'];

    public function show(
        Shipment $shipment,
        ShipmentMarginService $margins,
        ShipmentLabelService $labels,
        ShipmentQuoteRequestFactory $requests,
    ): View {
        RequiredRoles::requireAny(self::VIEWER_ROLES);
        $shipment->load([
            'client', 'job', 'carrier', 'selectedQuote', 'carrierCost', 'pods.podDocument', 'trackingEvents',
            'quotes' => fn ($query) => $query->with('carrier')->latest('id'),
            'extraCharges.reportedBy', 'redeliveryOf', 'redelivery', // CHANGE_REQUESTS #135 (audit TMS-11 / TMS-10)
        ]);

        // 2026-09-10 audit: the 人工报价 form is only offered for the stages whose booking request can be built (sender,
        // receiver and packages known) — ManualQuoteService refuses everything else with details_unavailable.
        $manualQuoteStages = in_array($shipment->status, ['quoting', 'quoted'], true)
            ? array_values(array_filter(
                ['preliminary', 'final'],
                fn (string $stage): bool => $requests->build($shipment, $stage) !== null,
            ))
            : [];

        return view('transport::shipments.show', [
            'shipment' => $shipment,
            'orderNo' => $shipment->order_id === null ? null : DB::table('orders')->where('id', $shipment->order_id)->value('order_no'), // header link text (tester feedback 2026-09-10)
            'clientPreference' => $shipment->order_id === null ? null : json_decode((string) (DB::table('orders')->where('id', $shipment->order_id)->value('transport_preference') ?? 'null'), true), // CHANGE_REQUESTS #118: what the client chose with the 估价
            // 我方上门提货 (CHANGE_REQUESTS #124): the 预报单 behind an inbound collection (Warehouse's table, read-only) and its pickup / warehouse parties.
            'asnNo' => $shipment->asn_id === null ? null : DB::table('asns')->where('id', $shipment->asn_id)->value('asn_no'),
            'collectionParties' => $shipment->isCollection() ? ['sender' => data_get($shipment->selectedQuote?->raw_response ?? $shipment->quotes->first()?->raw_response, '_quote_request.sender', []), 'receiver' => data_get($shipment->selectedQuote?->raw_response ?? $shipment->quotes->first()?->raw_response, '_quote_request.receiver', [])] : null,
            'margin' => $margins->shipment($shipment),
            'manualServices' => CarrierService::query()
                ->with('carrier')
                ->where('source', 'manual')
                ->where('active', true)
                ->orderBy('carrier_id')
                ->orderBy('service_level')
                ->get(),
            'manualQuoteStages' => $manualQuoteStages,
            'canPrintLabel' => $labels->available($shipment),
            // CHANGE_REQUESTS #135 (audit TMS-10): after 创建重派运输单 the new page reminds the planner of the TR-REDELIVERY step on the original.
            'redeliveryHintShipment' => session('redelivery_hint') !== null ? Shipment::query()->find((int) session('redelivery_hint')) : null,
        ]);
    }
}
