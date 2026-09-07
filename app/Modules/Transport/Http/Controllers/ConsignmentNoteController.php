<?php

namespace App\Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transport\Models\Shipment;
use App\Modules\Transport\Services\ConsignmentNotePdf;
use App\Modules\Transport\Services\PackageManifest;
use Symfony\Component\HttpFoundation\Response;

class ConsignmentNoteController extends Controller
{
    public function __invoke(Shipment $shipment, PackageManifest $manifest, ConsignmentNotePdf $pdf): Response
    {
        $filename = $shipment->shipment_no.'-consignment-note.pdf';

        return $pdf->render($shipment, $manifest->forShipment($shipment))->stream($filename);
    }
}
