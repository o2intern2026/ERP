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
        .parties { width: 100%; margin: 16px 0; border-collapse: collapse; }
        .parties th { width: 50%; padding: 6px; text-align: left; background: #f1f1f1; border: 1px solid #aaa; font-size: 12px; }
        .parties td { width: 50%; padding: 8px; vertical-align: top; border: 1px solid #aaa; line-height: 1.5; }
        .party-name { font-size: 13px; font-weight: bold; }
        .flag { display: inline-block; padding: 2px 6px; border: 2px solid #111; font-weight: bold; margin-top: 6px; }
        .packages { width: 100%; border-collapse: collapse; }
        .packages th, .packages td { padding: 6px; border: 1px solid #aaa; text-align: left; }
        .num { text-align: right; }
        .receipt { width: 100%; margin-top: 28px; border-collapse: collapse; page-break-inside: avoid; }
        .receipt th { padding: 6px; text-align: left; border: 1px solid #aaa; background: #f1f1f1; }
        .receipt td { width: 33%; padding: 26px 8px 6px; border: 1px solid #aaa; vertical-align: bottom; color: #555; }
        .footer { margin-top: 24px; color: #555; }
    </style>
</head>
<body>
    {{-- CHANGE_REQUESTS #135 (audit GAP-05): the controller / ConsignmentNotePdf hand over the parties, the order number and the
         delivery instructions for every shipment; a template rendered without them (older tests) still prints the rest. --}}
    @php($parties = $parties ?? ['sender' => [], 'receiver' => []])
    @php($sender = (array) ($parties['sender'] ?? []))
    @php($receiver = (array) ($parties['receiver'] ?? []))
    @php($addressLine = fn (array $party): string => collect([$party['address'] ?? null, trim(($party['suburb'] ?? '').' '.($party['state'] ?? '').' '.($party['postcode'] ?? ''))])->filter(fn ($v) => trim((string) $v) !== '')->implode(', '))
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
            @if ($shipment->isCollection())
                <th>{{ __('pdf.consignment_note.asn') }}</th>
                <td>{{ $asnNo ?? $shipment->asn_id }}</td>
            @else
                <th>{{ __('pdf.consignment_note.order') }}</th>
                <td>{{ $orderNo ?? $shipment->order_id }}</td>
            @endif
            <th>{{ __('pdf.consignment_note.carrier') }}</th>
            <td>{{ $shipment->carrier?->name ?? __('pdf.consignment_note.not_selected') }}</td>
        </tr>
        <tr>
            <th>{{ __('pdf.consignment_note.service_level') }}</th>
            <td>{{ $shipment->service_level ? __('pdf.service_levels.'.$shipment->service_level) : __('pdf.consignment_note.not_selected') }}</td>
            <th>{{ __('pdf.consignment_note.tracking') }}</th>
            <td>{{ filled($shipment->tracking_number) ? $shipment->tracking_number : '—' }}</td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <th>{{ $shipment->isCollection() ? __('pdf.consignment_note.collect_from') : __('pdf.consignment_note.from') }}</th>
            <th>{{ __('pdf.consignment_note.deliver_to') }}</th>
        </tr>
        <tr>
            <td>
                <div class="party-name">{{ $sender['name'] ?? '' }}</div>
                @if (($sender['company_name'] ?? '') !== '' && ($sender['company_name'] ?? '') !== ($sender['name'] ?? ''))<div>{{ $sender['company_name'] }}</div>@endif
                <div>{{ $addressLine($sender) }}</div>
                @if (! empty($sender['phone']))<div>{{ __('pdf.consignment_note.phone') }}: {{ $sender['phone'] }}</div>@endif
            </td>
            <td>
                <div class="party-name">{{ $receiver['name'] ?? '' }}</div>
                @if (($receiver['company_name'] ?? '') !== '' && ($receiver['company_name'] ?? '') !== ($receiver['name'] ?? ''))<div>{{ $receiver['company_name'] }}</div>@endif
                <div>{{ $addressLine($receiver) }}</div>
                @if (! empty($receiver['phone']))<div>{{ __('pdf.consignment_note.phone') }}: {{ $receiver['phone'] }}</div>@endif
                @if (filled($instructions ?? null))<div><em>{{ __('pdf.consignment_note.instructions') }}: {{ $instructions }}</em></div>@endif
                <div>{{ __('pdf.consignment_note.tailgate') }}: {{ $shipment->tailgate_required ? __('pdf.consignment_note.yes') : __('pdf.consignment_note.no') }}</div>
                @if ($shipment->tailgate_required)<div class="flag">{{ __('pdf.consignment_note.tailgate_flag') }}</div>@endif
            </td>
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

    <table class="receipt">
        <tr><th colspan="3">{{ __('pdf.consignment_note.received_title') }}</th></tr>
        <tr>
            <td>{{ __('pdf.consignment_note.name') }}</td>
            <td>{{ __('pdf.consignment_note.signature') }}</td>
            <td>{{ __('pdf.consignment_note.date') }}</td>
        </tr>
    </table>

    <p class="footer">{{ __('pdf.consignment_note.generated_at', ['time' => $generatedAt->format('d M Y H:i T')]) }}</p>
</body>
</html>
