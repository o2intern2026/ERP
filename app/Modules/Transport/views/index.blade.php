@extends('layouts.app')

@section('title', __('transport.title'))

@section('content')
    <h1>{{ __('transport.title') }}</h1>

    @if ($shipments->isEmpty())
        <p>{{ __('transport.shipments.empty') }}</p>
    @else
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('transport.shipments.number') }}</th>
                    <th>{{ __('transport.shipments.job') }}</th>
                    <th>{{ __('transport.shipments.type') }}</th>
                    <th>{{ __('transport.shipments.status') }}</th>
                    <th>{{ __('transport.shipments.carrier') }}</th>
                    <th>{{ __('transport.shipments.service_level') }}</th>
                    <th>{{ __('transport.shipments.tracking_number') }}</th>
                    <th class="num">{{ __('transport.shipments.quote_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipments as $shipment)
                    <tr>
                        <td><a href="{{ route('transport.shipments.show', $shipment) }}">{{ $shipment->shipment_no }}</a></td>
                        <td>{{ $shipment->job?->job_no ?? $shipment->job_id }}</td>
                        <td>{{ __('transport.shipment_types.'.$shipment->shipment_type) }}</td>
                        <td><span class="badge">{{ __('transport.statuses.'.$shipment->status) }}</span></td>
                        <td>{{ $shipment->carrier?->name ?? __('transport.not_selected') }}</td>
                        <td>{{ $shipment->service_level ? __('transport.service_levels.'.$shipment->service_level) : __('transport.not_selected') }}</td>
                        <td>{{ $shipment->tracking_number ?: __('transport.not_selected') }}</td>
                        <td class="num">{{ $shipment->quotes_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $shipments->links() }}
    @endif
@endsection
