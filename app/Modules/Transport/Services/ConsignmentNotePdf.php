<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;

final class ConsignmentNotePdf
{
    /** @param iterable<array<string, mixed>|object> $packages */
    public function render(Shipment $shipment, iterable $packages): DomPdf
    {
        $rows = Collection::make($packages)
            ->map(fn (array|object $package): array => (array) $package)
            ->values();

        return Pdf::loadView('transport::shipments.consignment-note', [
            'shipment' => $shipment->loadMissing(['client', 'job', 'carrier']),
            'packages' => $rows,
            'packageCount' => $rows->count(),
            'totalWeightKg' => $rows->sum(fn (array $package): float => (float) ($package['weight_kg'] ?? 0)),
            'generatedAt' => now(),
        ])->setPaper('a4');
    }
}
