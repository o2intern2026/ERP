<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentLabelService;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShipmentLabelController extends Controller
{
    public function __invoke(Request $request, Shipment $shipment, ShipmentLabelService $labels): Response
    {
        abort_unless(
            $request->user()?->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'transport_operator']),
            403,
        );

        try {
            $document = $labels->document($shipment);
        } catch (DomainException $exception) {
            abort(422, $exception->getMessage());
        }

        return response($document['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
