<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ShipmentLabelService;
use App\Support\Auth\RequiredRoles;
use DomainException;
use Symfony\Component\HttpFoundation\Response;

class ShipmentLabelController extends Controller
{
    public const ROLES = ['admin', 'customer_service', 'dispatcher', 'transport_operator'];

    public function __invoke(Shipment $shipment, ShipmentLabelService $labels): Response
    {
        RequiredRoles::requireAny(self::ROLES);

        try {
            $document = $labels->document($shipment);
        } catch (DomainException $exception) {
            // 2026-09-10 audit: back on the shipment page with the reason instead of a bare 422 error page.
            return redirect()
                ->route('transport.shipments.show', $shipment)
                ->withErrors(['label' => $exception->getMessage()]);
        }

        return response($document['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
