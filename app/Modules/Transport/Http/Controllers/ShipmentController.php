<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentLabelService;
use App\Modules\Transport\Services\ShipmentMarginService;
use App\Modules\Transport\Services\ShipmentQuoteRequestFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class ShipmentController extends Controller
{
    public function show(
        Shipment $shipment,
        ShipmentMarginService $margins,
        ShipmentLabelService $labels,
        ShipmentQuoteRequestFactory $requests,
    ): View {
        $shipment->load([
            'client', 'job', 'carrier', 'selectedQuote', 'carrierCost', 'pods.podDocument', 'trackingEvents',
            'quotes' => fn ($query) => $query->with('carrier')->latest('id'),
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
            'orderNo' => DB::table('orders')->where('id', $shipment->order_id)->value('order_no'), // header link text (tester feedback 2026-09-10)
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
        ]);
    }
}
