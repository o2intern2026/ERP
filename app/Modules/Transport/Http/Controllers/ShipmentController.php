<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use Illuminate\Contracts\View\View;

class ShipmentController extends Controller
{
    public function show(Shipment $shipment): View
    {
        return view('transport::shipments.show', [
            'shipment' => $shipment->load([
                'client', 'job', 'carrier', 'selectedQuote',
                'quotes' => fn ($query) => $query->with('carrier')->latest('id'),
            ]),
        ]);
    }
}
