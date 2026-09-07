<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use DomainException;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorSVG;

final class OwnFleetLabelPdf
{
    /** @param list<array<string, mixed>> $packages */
    public function render(Shipment $shipment, array $packages, array $receiver, string $carrierName): DomPdf
    {
        $count = count($packages);
        if ($count === 0) {
            throw new DomainException(__('transport.labels.no_packages'));
        }

        $generator = new BarcodeGeneratorSVG;
        $labels = collect($packages)->values()->map(function (array $package, int $index) use ($count, $generator): array {
            $barcode = trim((string) ($package['carton_label'] ?? ''));
            if ($barcode === '') {
                throw new DomainException(__('transport.labels.missing_carton_label'));
            }

            return [
                'package' => $package,
                'position' => $index + 1,
                'count' => $count,
                'barcode' => $barcode,
                'barcode_data_uri' => 'data:image/svg+xml;base64,'.base64_encode(
                    $generator->getBarcode($barcode, BarcodeGenerator::TYPE_CODE_128, 2, 64)
                ),
            ];
        });

        return Pdf::loadView('transport::shipments.label', [
            'shipment' => $shipment,
            'receiver' => $receiver,
            'carrierName' => $carrierName,
            'labels' => $labels,
        ])->setPaper([0, 0, 288, 432]);
    }
}
