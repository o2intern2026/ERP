<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; size: 4in 6in; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: DejaVu Sans, sans-serif; }
        .label { height: 390pt; padding: 16pt; }
        .label:not(:last-child) { page-break-after: always; }
        .header { border-bottom: 3pt solid #111; padding-bottom: 8pt; }
        .carrier { font-size: 18pt; font-weight: bold; }
        .service { float: right; font-size: 10pt; }
        .caption { margin-top: 12pt; font-size: 8pt; font-weight: bold; letter-spacing: 1pt; }
        .recipient { font-size: 17pt; font-weight: bold; line-height: 1.2; }
        .address { font-size: 12pt; line-height: 1.35; }
        .reference { width: 100%; margin-top: 12pt; border-collapse: collapse; }
        .reference td { border: 1pt solid #111; padding: 5pt; font-size: 9pt; }
        .reference strong { display: block; font-size: 12pt; }
        .barcode { margin-top: 14pt; text-align: center; }
        .barcode img { width: 100%; height: 64pt; }
        .barcode-code { margin-top: 4pt; font-size: 11pt; font-weight: bold; letter-spacing: 1pt; }
        .package-meta { margin-top: 8pt; font-size: 9pt; line-height: 1.5; text-align: center; }
    </style>
</head>
<body>
@foreach ($labels as $label)
    <section class="label">
        <div class="header">
            <span class="carrier">{{ $carrierName }}</span>
            <span class="service">{{ __('pdf.label.own_fleet') }}</span>
        </div>

        <div class="caption">{{ __('pdf.label.ship_to') }}</div>
        <div class="recipient">{{ $receiver['name'] }}</div>
        <div class="address">
            {{ $receiver['address'] }}<br>
            {{ $receiver['suburb'] }} {{ $receiver['state'] }} {{ $receiver['postcode'] }}
        </div>

        <table class="reference">
            <tr>
                <td>{{ __('pdf.label.shipment') }}<strong>{{ $shipment->shipment_no }}</strong></td>
                <td>{{ __('pdf.label.package') }}<strong>{{ $label['position'] }} / {{ $label['count'] }}</strong></td>
            </tr>
        </table>

        <div class="barcode">
            <img src="{{ $label['barcode_data_uri'] }}" alt="{{ $label['barcode'] }}">
            <div class="barcode-code">{{ $label['barcode'] }}</div>
        </div>
        <div class="package-meta">
            <div>{{ __('pdf.label.weight') }}: {{ number_format((float) $label['package']['weight_kg'], 3) }} kg</div>
            <div>
                {{ __('pdf.label.dimensions') }}:
                {{ $label['package']['length_mm'] }} x {{ $label['package']['width_mm'] }} x {{ $label['package']['height_mm'] }} mm
            </div>
        </div>
    </section>
@endforeach
</body>
</html>
