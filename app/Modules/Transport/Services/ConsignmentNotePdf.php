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
    public function __construct(private readonly ShipmentQuoteRequestFactory $requests) {}

    /** @param iterable<array<string, mixed>|object> $packages */
    public function render(Shipment $shipment, iterable $packages): DomPdf
    {
        return Pdf::loadView('transport::shipments.consignment-note', $this->data($shipment, $packages))->setPaper('a4');
    }

    /**
     * The template's data. 我方上门提货 (CHANGE_REQUESTS #124): a collection prints the 预报单 number and its pickup → warehouse parties
     * instead of an order. CHANGE_REQUESTS #135 (audit GAP-05): EVERY shipment prints From / Deliver to — the live order / ASN and
     * warehouse data first (the paper travels with the goods, so a corrected consignee must be on it), the quote request's snapshot
     * filling whatever is missing — plus the order number, the consignee's delivery instructions, the tracking number and a signing box.
     *
     * @param  iterable<array<string, mixed>|object>  $packages
     * @return array<string, mixed>
     */
    public function data(Shipment $shipment, iterable $packages): array
    {
        $rows = Collection::make($packages)
            ->map(fn (array|object $package): array => (array) $package)
            ->values();

        $shipment->loadMissing(['client', 'job', 'carrier', 'selectedQuote']);
        $raw = $shipment->selectedQuote?->raw_response ?? $shipment->quotes()->latest('id')->value('raw_response') ?? [];
        $assembled = $this->requests->parties($shipment);
        $filled = fn (array $party): array => array_filter($party, fn ($value): bool => $value !== null && $value !== '');

        return [
            'shipment' => $shipment,
            'asnNo' => $shipment->asn_id !== null && Schema::hasTable('asns') ? DB::table('asns')->where('id', $shipment->asn_id)->value('asn_no') : null,
            'orderNo' => $assembled['order_no'],
            'instructions' => $assembled['instructions'],
            'parties' => [
                'sender' => $filled($assembled['sender']) + $filled((array) data_get($raw, '_quote_request.sender', [])),
                'receiver' => $filled($assembled['receiver']) + $filled((array) data_get($raw, '_quote_request.receiver', [])),
            ],
            'packages' => $rows,
            'packageCount' => $rows->count(),
            'totalWeightKg' => $rows->sum(fn (array $package): float => (float) ($package['weight_kg'] ?? 0)),
            'generatedAt' => now(),
        ];
    }
}
