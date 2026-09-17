<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ConsignmentNotePdf;
use App\Modules\Transport\Services\PackageManifest;
use App\Support\Auth\RequiredRoles;
use Symfony\Component\HttpFoundation\Response;

class ConsignmentNoteController extends Controller
{
    public function __invoke(Shipment $shipment, PackageManifest $manifest, ConsignmentNotePdf $pdf): Response
    {
        RequiredRoles::requireAny(ShipmentController::VIEWER_ROLES); // CHANGE_REQUESTS #130: the driver page never links here, so the driver has no use for it
        $filename = $shipment->shipment_no.'-consignment-note.pdf';

        return $pdf->render($shipment, $manifest->forShipment($shipment))->stream($filename);
    }
}
