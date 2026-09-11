<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.pod.title') }}</title>
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
    @php
        // DriverPodService hands the capture time over as an ISO-8601 string; print it in the app time zone, or as given if unparseable.
        try {
            $deliveredAtLabel = \Illuminate\Support\Carbon::parse($deliveredAt)->timezone(config('app.timezone'))->format('d M Y H:i');
        } catch (\Throwable) {
            $deliveredAtLabel = $deliveredAt;
        }
    @endphp
    <h1>{{ __('pdf.pod.title') }}</h1>
    <table>
        <tr><th>{{ __('pdf.pod.shipment') }}</th><td>{{ $shipment->shipment_no }}</td></tr>
        <tr><th>{{ __('pdf.pod.stop') }}</th><td>{{ $stop->seq }}</td></tr>
        <tr><th>{{ __('pdf.pod.recipient_name') }}</th><td>{{ $recipientName }}</td></tr>
        <tr><th>{{ __('pdf.pod.delivered_at') }}</th><td>{{ $deliveredAtLabel }}</td></tr>
    </table>
    <h2>{{ __('pdf.pod.signature') }}</h2>
    <img class="signature" src="{{ $signatureDataUri }}" alt="{{ __('pdf.pod.signature') }}">
    <h2>{{ __('pdf.pod.photos') }}</h2>
    @foreach ($photoDataUris as $photoDataUri)
        <div class="photo"><img src="{{ $photoDataUri }}" alt="{{ __('pdf.pod.photos') }}"></div>
    @endforeach
</body>
</html>
