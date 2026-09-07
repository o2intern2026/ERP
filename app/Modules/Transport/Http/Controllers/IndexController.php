<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentMarginService;
use Illuminate\Contracts\View\View;

class IndexController extends Controller
{
    public function __invoke(ShipmentMarginService $margins): View
    {
        $shipments = Shipment::query()
            ->with(['job', 'carrier', 'selectedQuote', 'carrierCost'])
            ->withCount('quotes')
            ->latest('id')
            ->paginate(25);

        return view('transport::index', [
            'shipments' => $shipments,
            'margins' => $shipments->getCollection()->mapWithKeys(
                fn (Shipment $shipment): array => [$shipment->id => $margins->shipment($shipment)]
            ),
        ]);
    }
}
