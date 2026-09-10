@extends('layouts.app')

@section('title', $run->run_no)

@section('content')
    <p><a href="{{ route('transport.runs.index') }}">{{ __('transport.runs.back') }}</a></p>
    <h1>{{ $run->run_no }}</h1>

    <article class="kv-card"><dl class="kv-2">
        <dt>{{ __('transport.runs.date') }}</dt>
        <dd>{{ $run->run_date->format('Y-m-d') }}</dd>
        <dt>{{ __('transport.runs.driver') }}</dt>
        <dd>{{ $run->driver->name }}</dd>
        <dt>{{ __('transport.runs.vehicle') }}</dt>
        <dd>{{ $run->vehicle }}</dd>
        <dt>{{ __('transport.runs.status') }}</dt>
        <dd>{!! \App\Support\Ui\StatusBadge::render('transport.run_statuses.', $run->status) !!}</dd>
    </dl></article>

    @if ($run->status === 'planned')
        <h2>{{ __('transport.runs.add_shipment') }}</h2>
        @if ($eligibleShipments->isEmpty())
            <p>{{ __('transport.runs.no_eligible_shipments') }}</p>
        @else
            <form method="post" action="{{ route('transport.runs.stops.store', $run) }}">
                @csrf
                <label>
                    {{ __('transport.runs.shipment') }}
                    <select name="shipment_id" required>
                        <option value="">{{ __('transport.runs.choose_shipment') }}</option>
                        @foreach ($eligibleShipments as $shipment)
                            <option value="{{ $shipment->id }}" @selected((string) old('shipment_id') === (string) $shipment->id)>
                                {{ $shipment->shipment_no }} — {{ $shipment->client->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>
                    {{ __('transport.runs.eta') }}
                    <input type="datetime-local" name="eta" value="{{ old('eta') }}">
                </label>
                <button type="submit">{{ __('transport.runs.add') }}</button>
            </form>
        @endif
    @endif

    <h2>{{ __('transport.runs.stops') }}</h2>
    @if ($run->stops->isEmpty())
        <p>{{ __('transport.runs.no_stops') }}</p>
    @else
        <form method="post" action="{{ route('transport.runs.stops.reorder', $run) }}">
            @csrf
            @method('patch')
            @if ($run->status === 'planned')
                <p><small>{{ __('transport.runs.reorder_hint') }}</small></p>
            @endif
            <table class="dense">
                <thead>
                    <tr>
                        <th>{{ __('transport.runs.sequence') }}</th>
                        <th>{{ __('transport.runs.shipment') }}</th>
                        <th>{{ __('transport.shipments.client') }}</th>
                        <th>{{ __('transport.runs.eta') }}</th>
                        <th>{{ __('transport.runs.stop_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($run->stops as $stop)
                        <tr>
                            <td>
                                @if ($run->status === 'planned')
                                    <input type="number" name="positions[{{ $stop->id }}]" value="{{ old('positions.'.$stop->id, $stop->seq) }}" min="1" required aria-label="{{ __('transport.runs.sequence') }}">
                                @else
                                    {{ $stop->seq }}
                                @endif
                            </td>
                            <td><a href="{{ route('transport.shipments.show', $stop->shipment) }}">{{ $stop->shipment->shipment_no }}</a></td>
                            <td>{{ $stop->shipment->client->name }}</td>
                            <td>{{ $stop->eta?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{!! \App\Support\Ui\StatusBadge::render('transport.stop_statuses.', $stop->status) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($run->status === 'planned')
                <button type="submit">{{ __('transport.runs.save_order') }}</button>
            @endif
        </form>
    @endif
@endsection
