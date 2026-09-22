<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 6mm; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; }
        /* 116 mm + 20 mm padding = 136 mm, inside the 138 mm printable height — 138 + 20 used to spill a blank page after every label (audit 2026-09-22). */
        .label { page-break-after: always; height: 116mm; text-align: center; padding-top: 20mm; }
        .label:last-child { page-break-after: auto; }
        .code { font-size: 30pt; font-weight: bold; margin: 6mm 0 2mm; }
        .type { font-size: 12pt; color: #555; }
        .tier { display: inline-block; border: 1.2mm solid #000; padding: 1.5mm 4mm; font-size: 16pt; font-weight: bold; margin-top: 4mm; }
    </style>
</head>
<body>
{{-- CHANGE_REQUESTS #131: the barcode is the short token L<id> (ScanCodes); the text keeps the full location code. --}}
@foreach ($locations as $l)
    <div class="label">
        <div style="display:inline-block">{!! $barcodes[$l->id] !!}</div>
        <div class="code">{{ $l->full_code }}</div>
        <div class="type">{{ $l->warehouse->name }} · {{ __('pdf.location_types.'.$l->type) }}@if ($l->rack_level) · {{ __('pdf.location_label.level', ['level' => $l->rack_level]) }}@endif</div>
        @if ($l->type === 'storage' && $l->storage_tier === 'bottom')
            <div class="tier">{{ __('pdf.storage_tiers.bottom') }}</div>
        @endif
    </div>
@endforeach
</body>
</html>
