<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ __('pdf.consignment_note.title') }} {{ $shipment->shipment_no }}</title>
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
    <h1>{{ __('pdf.consignment_note.title') }}</h1>
    <strong>{{ $shipment->shipment_no }}</strong>

    <table class="meta">
        <tr>
            <th>{{ __('pdf.consignment_note.job') }}</th>
            <td>{{ $shipment->job->job_no }}</td>
            <th>{{ __('pdf.consignment_note.client') }}</th>
            <td>{{ $shipment->client->name }}</td>
        </tr>
        <tr>
            <th>{{ __('pdf.consignment_note.order') }}</th>
            <td>{{ $shipment->order_id }}</td>
            <th>{{ __('pdf.consignment_note.carrier') }}</th>
            <td>{{ $shipment->carrier?->name ?? __('pdf.consignment_note.not_selected') }}</td>
        </tr>
        <tr>
            <th>{{ __('pdf.consignment_note.service_level') }}</th>
            <td>{{ $shipment->service_level ? __('pdf.service_levels.'.$shipment->service_level) : __('pdf.consignment_note.not_selected') }}</td>
            <th>{{ __('pdf.consignment_note.tailgate') }}</th>
            <td>{{ $shipment->tailgate_required ? __('pdf.consignment_note.yes') : __('pdf.consignment_note.no') }}</td>
        </tr>
    </table>

    <h2>{{ __('pdf.consignment_note.contents') }}</h2>
    @if ($packages->isEmpty())
        <p>{{ __('pdf.consignment_note.no_packages') }}</p>
    @else
        <table class="packages">
            <thead>
                <tr>
                    <th>{{ __('pdf.consignment_note.label') }}</th>
                    <th>{{ __('pdf.consignment_note.package_type') }}</th>
                    <th class="num">{{ __('pdf.consignment_note.weight') }}</th>
                    <th>{{ __('pdf.consignment_note.dimensions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($packages as $package)
                    <tr>
                        <td>{{ $package['carton_label'] }}</td>
                        <td>{{ filled($package['package_type'] ?? null) ? __('pdf.package_types.'.$package['package_type']) : '' }}</td>
                        <td class="num">{{ number_format((float) $package['weight_kg'], 3) }}</td>
                        <td>{{ $package['length_mm'] }} × {{ $package['width_mm'] }} × {{ $package['height_mm'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p><strong>{{ __('pdf.consignment_note.total_packages') }}:</strong> {{ $packageCount }}</p>
    <p><strong>{{ __('pdf.consignment_note.total_weight') }}:</strong> {{ number_format($totalWeightKg, 3) }} kg</p>
    <p class="footer">{{ __('pdf.consignment_note.generated_at', ['time' => $generatedAt->format('d M Y H:i T')]) }}</p>
</body>
</html>
