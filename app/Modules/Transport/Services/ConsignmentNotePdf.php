<?php

namespace App\Modules\Transport\Services;

use App\Modules\Transport\Models\Shipment;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ConsignmentNotePdf
{
    /** @param iterable<array<string, mixed>|object> $packages */
    public function render(Shipment $shipment, iterable $packages): DomPdf
    {
        $rows = Collection::make($packages)
            ->map(fn (array|object $package): array => (array) $package)
            ->values();

        $shipment->loadMissing(['client', 'job', 'carrier', 'selectedQuote']);
        // 我方上门提货 (CHANGE_REQUESTS #124): a collection prints the 预报单 number and its pickup → warehouse parties instead of an order.
        $raw = $shipment->selectedQuote?->raw_response ?? $shipment->quotes()->latest('id')->value('raw_response') ?? [];

        return Pdf::loadView('transport::shipments.consignment-note', [
            'shipment' => $shipment,
            'asnNo' => $shipment->asn_id !== null && Schema::hasTable('asns') ? DB::table('asns')->where('id', $shipment->asn_id)->value('asn_no') : null,
            'parties' => ['sender' => (array) data_get($raw, '_quote_request.sender', []), 'receiver' => (array) data_get($raw, '_quote_request.receiver', [])],
            'packages' => $rows,
            'packageCount' => $rows->count(),
            'totalWeightKg' => $rows->sum(fn (array $package): float => (float) ($package['weight_kg'] ?? 0)),
            'generatedAt' => now(),
        ])->setPaper('a4');
    }
}
