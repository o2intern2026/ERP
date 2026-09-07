<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\RunStop;
use App\Modules\Transport\Models\Shipment;
use Barryvdh\DomPDF\PDF;

final class PodPdf
{
    /** @param list<string> $photoDataUris */
    public function render(
        Shipment $shipment,
        RunStop $stop,
        string $recipientName,
        string $deliveredAt,
        string $signatureDataUri,
        array $photoDataUris,
    ): PDF {
        return app('dompdf.wrapper')->loadView('transport::driver.pod-pdf', [
            'shipment' => $shipment,
            'stop' => $stop,
            'recipientName' => $recipientName,
            'deliveredAt' => $deliveredAt,
            'signatureDataUri' => $signatureDataUri,
            'photoDataUris' => $photoDataUris,
        ])->setPaper('a4');
    }
}
