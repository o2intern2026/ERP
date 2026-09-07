<!doctype html>
<html lang="zh">
<head>
    <meta charset="utf-8">
    <title>{{ __('transport.consignment_note.title') }} {{ $shipment->shipment_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { margin-bottom: 4px; }
        .meta { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .meta th, .meta td { width: 25%; padding: 5px; text-align: left; border-bottom: 1px solid #ddd; }
        .packages { width: 100%; border-collapse: collapse; }
        .packages th, .packages td { padding: 6px; border: 1px solid #aaa; text-align: left; }
        .num { text-align: right; }
        .footer { margin-top: 24px; color: #555; }
    </style>
</head>
<body>
    <h1>{{ __('transport.consignment_note.title') }}</h1>
    <strong>{{ $shipment->shipment_no }}</strong>

    <table class="meta">
        <tr>
            <th>{{ __('transport.shipments.job') }}</th>
            <td>{{ $shipment->job->job_no }}</td>
            <th>{{ __('transport.shipments.client') }}</th>
            <td>{{ $shipment->client->name }}</td>
        </tr>
        <tr>
            <th>{{ __('transport.shipments.order') }}</th>
            <td>{{ $shipment->order_id }}</td>
            <th>{{ __('transport.shipments.carrier') }}</th>
            <td>{{ $shipment->carrier?->name ?? __('transport.not_selected') }}</td>
        </tr>
        <tr>
            <th>{{ __('transport.shipments.service_level') }}</th>
            <td>{{ $shipment->service_level ? __('transport.service_levels.'.$shipment->service_level) : __('transport.not_selected') }}</td>
            <th>{{ __('transport.shipments.tailgate') }}</th>
            <td>{{ $shipment->tailgate_required ? __('transport.yes') : __('transport.no') }}</td>
        </tr>
    </table>

    <h2>{{ __('transport.consignment_note.contents') }}</h2>
    @if ($packages->isEmpty())
        <p>{{ __('transport.consignment_note.no_packages') }}</p>
    @else
        <table class="packages">
            <thead>
                <tr>
                    <th>{{ __('transport.consignment_note.label') }}</th>
                    <th>{{ __('transport.consignment_note.package_type') }}</th>
                    <th class="num">{{ __('transport.consignment_note.weight') }}</th>
                    <th>{{ __('transport.consignment_note.dimensions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($packages as $package)
                    <tr>
                        <td>{{ $package['carton_label'] }}</td>
                        <td>{{ $package['package_type'] }}</td>
                        <td class="num">{{ number_format((float) $package['weight_kg'], 3) }}</td>
                        <td>{{ $package['length_mm'] }} × {{ $package['width_mm'] }} × {{ $package['height_mm'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p><strong>{{ __('transport.consignment_note.total_packages') }}:</strong> {{ $packageCount }}</p>
    <p><strong>{{ __('transport.consignment_note.total_weight') }}:</strong> {{ number_format($totalWeightKg, 3) }} kg</p>
    <p class="footer">{{ __('transport.consignment_note.generated_at', ['time' => $generatedAt->format('Y-m-d H:i:s T')]) }}</p>
</body>
</html>
