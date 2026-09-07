<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\CarrierService;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentMarginService;
use Illuminate\Contracts\View\View;

class ShipmentController extends Controller
{
    public function show(Shipment $shipment, ShipmentMarginService $margins): View
    {
        return view('transport::shipments.show', [
            'shipment' => $shipment->load([
                'client', 'job', 'carrier', 'selectedQuote', 'carrierCost', 'pods.podDocument', 'trackingEvents',
                'quotes' => fn ($query) => $query->with('carrier')->latest('id'),
            ]),
            'margin' => $margins->shipment($shipment),
            'manualServices' => CarrierService::query()
                ->with('carrier')
                ->where('source', 'manual')
                ->where('active', true)
                ->orderBy('carrier_id')
                ->orderBy('service_level')
                ->get(),
        ]);
    }
}
