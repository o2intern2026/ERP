<?php

namespace App\Modules\Warehouse\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Picqer\Barcode\BarcodeGeneratorHTML;

/** B11: carton / pallet unit labels and location labels as 100 × 150 mm PDF pages, Code 128 (HTML renderer, no GD needed). */
final class LabelService
{
    private const PAGE_100x150_PT = [0, 0, 283.46, 425.2];

    public function unitLabels(Collection $units): string
    {
        $generator = new BarcodeGeneratorHTML;

        return Pdf::loadView('warehouse::labels.units', [
            'units' => $units->load(['asnLine.asn.client', 'asnLine.asn.job', 'location']),
            'barcodes' => $units->mapWithKeys(fn ($u) => [$u->id => $generator->getBarcode($u->label_code, BarcodeGeneratorHTML::TYPE_CODE_128, 2, 70)]),
        ])->setPaper(self::PAGE_100x150_PT)->output();
    }

    public function locationLabels(Collection $locations): string
    {
        $generator = new BarcodeGeneratorHTML;

        return Pdf::loadView('warehouse::labels.locations', [
            'locations' => $locations->load('warehouse'),
            'barcodes' => $locations->mapWithKeys(fn ($l) => [$l->id => $generator->getBarcode($l->full_code, BarcodeGeneratorHTML::TYPE_CODE_128, 2, 70)]),
        ])->setPaper(self::PAGE_100x150_PT)->output();
    }
}
