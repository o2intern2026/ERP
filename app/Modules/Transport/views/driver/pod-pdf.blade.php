<!doctype html>
<html lang="zh">
<head>
    <meta charset="utf-8">
    <title>{{ __('transport.driver.pod_title') }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #18212b; font-size: 12px; }
        h1, h2 { margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { border: 1px solid #cbd5e1; padding: 7px; text-align: left; }
        img { max-width: 100%; max-height: 280px; margin: 6px 0; }
        .signature { max-height: 150px; }
        .photo { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>{{ __('transport.driver.pod_title') }}</h1>
    <table>
        <tr><th>{{ __('transport.driver.shipment') }}</th><td>{{ $shipment->shipment_no }}</td></tr>
        <tr><th>{{ __('transport.driver.stop') }}</th><td>{{ $stop->seq }}</td></tr>
        <tr><th>{{ __('transport.driver.recipient_name') }}</th><td>{{ $recipientName }}</td></tr>
        <tr><th>{{ __('transport.driver.delivered_at') }}</th><td>{{ $deliveredAt }}</td></tr>
    </table>
    <h2>{{ __('transport.driver.signature') }}</h2>
    <img class="signature" src="{{ $signatureDataUri }}" alt="{{ __('transport.driver.signature') }}">
    <h2>{{ __('transport.driver.photos') }}</h2>
    @foreach ($photoDataUris as $photoDataUri)
        <div class="photo"><img src="{{ $photoDataUri }}" alt="{{ __('transport.driver.photos') }}"></div>
    @endforeach
</body>
</html>
