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
        .code { font-size: 13pt; font-weight: bold; letter-spacing: 1px; margin: 0 0 3mm; }
        .big { font-size: 26pt; font-weight: bold; line-height: 1.2; margin: 2mm 0; }
        .tier { display: inline-block; border: 1.2mm solid #000; padding: 1.5mm 4mm; font-size: 16pt; font-weight: bold; margin: 2mm 0 3mm; }
        .small { font-size: 8.5pt; color: #333; margin-top: 3mm; }
    </style>
</head>
<body>
{{-- CHANGE_REQUESTS #131: the barcode is the short token U<id> (ScanCodes); the text keeps the full label_code. Largest line = mark + cartons;
     the location is small print (it is the receiving dock when the label is printed); the description prints only without CJK. --}}
@foreach ($units as $u)
    @php($line = $u->asnLine)
    @php($description = \App\Modules\Warehouse\Services\LabelService::printableDescription($line->description))
    <div class="label">
        <div class="meta">{{ $line->asn->client->name }} · {{ $line->asn->job->job_no }} · {{ $line->asn->asn_no }}</div>
        <div class="barcode">{!! $barcodes[$u->id] !!}</div>
        <div class="code">{{ $u->label_code }}</div>
        <div class="big">{{ filled($line->consignment_mark) ? $line->consignment_mark.' · ' : '' }}{{ $u->qty_on_hand }} {{ __('pdf.units.carton') }}</div>
        @if ($u->required_storage_tier === 'bottom')
            <div class="tier">{{ __('pdf.storage_tiers.bottom') }}</div>
        @endif
        @if ($description !== null)
            <div class="meta">{{ $description }}</div>
        @endif
        <div class="meta">{{ __('pdf.unit_types.'.$u->unit_type) }} · {{ $u->qty_on_hand }} {{ __('pdf.units.carton') }}@if ($u->unit_type === 'pallet') · {{ $u->length_mm }}×{{ $u->width_mm }}×{{ $u->height_mm }} mm · {{ $u->weight_kg }} kg @endif</div>
        <div class="small">{{ __('pdf.unit_label.received_at') }} {{ $u->location?->full_code ?? '—' }}@if ($u->received_at) · {{ $u->received_at->format('d/m/Y') }}@endif</div>
    </div>
@endforeach
</body>
</html>
