<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 6mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; margin: 0; }
        .label { page-break-after: always; height: 136mm; }
        .label:last-child { page-break-after: auto; }
        .meta { font-size: 9.5pt; line-height: 1.4; }
        .barcode { margin: 2mm 0 1mm; }
        .big { font-size: 26pt; font-weight: bold; line-height: 1.2; margin: 2mm 0; }
        .title { font-size: 12pt; font-weight: bold; letter-spacing: 2px; }
        table { border-collapse: collapse; width: 100%; font-size: 9.5pt; margin-top: 3mm; }
        td { padding: 0.6mm 1mm; border-bottom: 0.2mm solid #999; }
        .num { text-align: right; }
        .small { font-size: 8.5pt; color: #333; margin-top: 3mm; }
    </style>
</head>
<body>
{{-- CHANGE_REQUESTS #166: the barcode is the short token P<id> (ScanCodes); the big text is the pallet number; the table lists the goods lines on the pallet. --}}
@foreach ($pallets as $p)
    @php($job = $p->units->first()?->asnLine?->asn?->job)
    <div class="label">
        <div class="title">{{ __('pdf.pallet_label.title') }}</div>
        <div class="meta">{{ $p->client?->name }}@if ($job) · {{ $job->job_no }}@endif</div>
        <div class="barcode">{!! $barcodes[$p->id] !!}</div>
        <div class="big">{{ $p->pallet_no }}</div>
        <div class="meta">{{ __('pdf.pallet_label.lines', ['lines' => $p->units->count(), 'cartons' => (int) $p->units->sum('qty_on_hand')]) }}@if ($p->pallet_class) · {{ __('pdf.pallet_classes.'.$p->pallet_class) }}@endif@if ($p->length_mm) · {{ $p->length_mm }}×{{ $p->width_mm }}×{{ $p->height_mm }} mm@endif@if ($p->weight_kg !== null) · {{ rtrim(rtrim((string) $p->weight_kg, '0'), '.') }} kg@endif</div>
        <table>
            @foreach ($p->units->sortBy('id') as $u)
                @php($description = \App\Modules\Warehouse\Services\LabelService::printableDescription($u->asnLine?->description ?? ''))
                <tr><td>{{ $u->label_code }}</td><td>{{ $u->asnLine?->consignment_mark }}@if ($description !== null) · {{ $description }}@endif</td><td class="num">{{ $u->qty_on_hand }} {{ __('pdf.units.carton') }}</td></tr>
            @endforeach
        </table>
        <div class="small">{{ __('pdf.unit_label.received_at') }} {{ $p->location?->full_code ?? '—' }}@if ($p->received_at) · {{ $p->received_at->format('d/m/Y') }}@endif</div>
    </div>
@endforeach
</body>
</html>
