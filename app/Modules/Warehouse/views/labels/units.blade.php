<!doctype html>
<html lang="zh">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 6mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; margin: 0; }
        .label { page-break-after: always; height: 138mm; }
        .label:last-child { page-break-after: auto; }
        .code { font-size: 16pt; font-weight: bold; letter-spacing: 1px; margin: 3mm 0 1mm; }
        .barcode { margin: 2mm 0 3mm; }
        .meta { font-size: 10pt; line-height: 1.5; }
        .big { font-size: 22pt; font-weight: bold; margin-top: 4mm; }
    </style>
</head>
<body>
@foreach ($units as $u)
    <div class="label">
        <div class="meta">{{ $u->asnLine->asn->client->name }} · {{ $u->asnLine->asn->job->job_no }} · {{ $u->asnLine->asn->asn_no }}</div>
        <div class="barcode">{!! $barcodes[$u->id] !!}</div>
        <div class="code">{{ $u->label_code }}</div>
        <div class="meta">
            {{ $u->asnLine->consignment_mark }}<br>
            {{ \Illuminate\Support\Str::limit($u->asnLine->description, 60) }}<br>
            {{ __('warehouse.unit_types.'.$u->unit_type) }} · {{ $u->qty_on_hand }} {{ __('warehouse.uoms.carton') }}
            @if ($u->unit_type === 'pallet') · {{ $u->length_mm }}×{{ $u->width_mm }}×{{ $u->height_mm }} mm · {{ $u->weight_kg }} kg @endif
        </div>
        <div class="big">{{ $u->location?->full_code ?? '—' }}</div>
    </div>
@endforeach
</body>
</html>
