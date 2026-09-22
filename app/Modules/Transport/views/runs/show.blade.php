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

    @role('admin|customer_service|dispatcher') {{-- CHANGE_REQUESTS #130: only planners add and reorder stops; the driver sees a read-only list --}}
    @if ($editable)
        {{-- CHANGE_REQUESTS #133 (audit TMS-01): a wrong date / driver / vehicle is corrected and a dead run cancelled, while no stop is delivered. --}}
        <details @if ($errors->hasAny(['run', 'run_date', 'driver_id', 'vehicle'])) open @endif>
            <summary>{{ __('transport.runs.edit') }}</summary>
            <p><small>{{ __('transport.runs.edit_hint') }}</small></p>
            <form method="post" action="{{ route('transport.runs.update', $run) }}">
                @csrf
                @method('patch')
                <label>
                    {{ __('transport.runs.date') }}
                    <x-date-field name="run_date" value="{{ old('run_date', $run->run_date->toDateString()) }}" required />
                </label>
                <label>
                    {{ __('transport.runs.driver') }}
                    <select name="driver_id" required>
                        <option value="">{{ __('transport.runs.choose_driver') }}</option>
                        @foreach ($drivers as $driver)
                            <option value="{{ $driver->id }}" @selected((string) old('driver_id', $run->driver_id) === (string) $driver->id)>
                                {{ $driver->name }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <label>
                    {{ __('transport.runs.vehicle') }}
                    <input type="text" name="vehicle" value="{{ old('vehicle', $run->vehicle) }}" maxlength="100" required>
                </label>
                <button type="submit">{{ __('transport.runs.save_changes') }}</button>
            </form>
        </details>
        <form method="post" action="{{ route('transport.runs.cancel', $run) }}" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('transport.runs.cancel_confirm') }}">
            @csrf
            <button type="submit" class="secondary outline">{{ __('transport.runs.cancel') }}</button>
        </form>
    @endif

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
                    <x-date-field name="eta" value="{{ old('eta') }}" time />
                </label>
                <button type="submit">{{ __('transport.runs.add') }}</button>
            </form>
        @endif
    @endif
    @endrole

    <h2>{{ __('transport.runs.stops') }}</h2>
    @if ($run->stops->isEmpty())
        <p>{{ __('transport.runs.no_stops') }}</p>
    @else
        @role('admin|customer_service|dispatcher')
        <form method="post" action="{{ route('transport.runs.stops.reorder', $run) }}">
            @csrf
            @method('patch')
            @if ($run->status === 'planned')
                <p><small>{{ __('transport.runs.reorder_hint') }}</small></p>
            @endif
        @endrole
            <table class="dense">
                <thead>
                    <tr>
                        <th>{{ __('transport.runs.sequence') }}</th>
                        <th>{{ __('transport.runs.shipment') }}</th>
                        <th>{{ __('transport.shipments.client') }}</th>
                        <th>{{ __('transport.runs.eta') }}</th>
                        <th>{{ __('transport.runs.stop_status') }}</th>
                        @role('admin|customer_service|dispatcher')
                        @if ($open)
                            <th>{{ __('transport.runs.actions') }}</th>
                        @endif
                        @endrole
                    </tr>
                </thead>
                <tbody>
                    @foreach ($run->stops as $stop)
                        <tr>
                            <td>
                                @if ($run->status === 'planned')
                                    @role('admin|customer_service|dispatcher')
                                    <input type="number" name="positions[{{ $stop->id }}]" value="{{ old('positions.'.$stop->id, $stop->seq) }}" min="1" required aria-label="{{ __('transport.runs.sequence') }}">
                                    @else
                                    {{ $stop->seq }}
                                    @endrole
                                @else
                                    {{ $stop->seq }}
                                @endif
                            </td>
                            <td>@role('admin|customer_service|dispatcher')<a href="{{ route('transport.shipments.show', $stop->shipment) }}">{{ $stop->shipment->shipment_no }}</a>@else{{ $stop->shipment->shipment_no }}@endrole</td>
                            <td>{{ $stop->shipment->client->name }}</td>
                            <td>{{ $stop->eta?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td>{!! \App\Support\Ui\StatusBadge::render('transport.stop_statuses.', $stop->status) !!}</td>
                            @role('admin|customer_service|dispatcher')
                            @if ($open)
                                <td>
                                    @if (in_array($stop->status, \App\Modules\Transport\Services\DeliveryRunService::OPEN_STOP_STATUSES, true))
                                        {{-- CHANGE_REQUESTS #133: 移出班次 posts the per-stop form rendered after the reorder form (a form cannot nest inside a form). --}}
                                        <button type="submit" form="remove-stop-{{ $stop->id }}" class="secondary outline">{{ __('transport.runs.remove_stop') }}</button>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            @endrole
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @role('admin|customer_service|dispatcher')
            @if ($run->status === 'planned')
                <button type="submit">{{ __('transport.runs.save_order') }}</button>
            @endif
        </form>
        @if ($open)
            @foreach ($run->stops as $stop)
                @if (in_array($stop->status, \App\Modules\Transport\Services\DeliveryRunService::OPEN_STOP_STATUSES, true))
                    <form id="remove-stop-{{ $stop->id }}" method="post" action="{{ route('transport.runs.stops.destroy', [$run, $stop]) }}" hidden onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('transport.runs.remove_stop_confirm') }}">
                        @csrf
                        @method('delete')
                    </form>
                @endif
            @endforeach
        @endif
            @endrole
    @endif
@endsection
