<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 6mm; }
        body { font-family: DejaVu Sans, sans-serif; margin: 0; }
        .label { page-break-after: always; height: 138mm; text-align: center; padding-top: 20mm; }
        .label:last-child { page-break-after: auto; }
        .code { font-size: 30pt; font-weight: bold; margin: 6mm 0 2mm; }
        .type { font-size: 12pt; color: #555; }
    </style>
</head>
<body>
@foreach ($locations as $l)
    <div class="label">
        <div style="display:inline-block">{!! $barcodes[$l->id] !!}</div>
        <div class="code">{{ $l->full_code }}</div>
        <div class="type">{{ $l->warehouse->name }} · {{ __('pdf.location_types.'.$l->type) }}</div>
    </div>
@endforeach
</body>
</html>
