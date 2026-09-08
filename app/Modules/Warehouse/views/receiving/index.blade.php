@extends('layouts.app')

@section('title', __('warehouse.receiving.worklist'))

@section('content')
    <header class="grid">
        <h1>{{ __('warehouse.receiving.worklist') }}</h1>
        @role('admin|warehouse_supervisor|warehouse_operator')
            <p style="text-align:right"><a role="button" class="secondary" href="{{ route('warehouse.receiving.unplanned.form') }}">{{ __('warehouse.receiving.unplanned.title') }}</a></p>
        @endrole
    </header>
    <p class="text-muted"><small>{{ __('warehouse.receiving.worklist_hint') }}</small></p>
    <form method="get" class="grid">
        <select name="client_id"><option value="">{{ __('warehouse.asns.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <select name="warehouse_id"><option value="">{{ __('warehouse.asns.warehouse') }}: {{ __('warehouse.warehouses.all') }}</option>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) ($filters['warehouse_id'] ?? 0) === $w->id)>{{ $w->code }}</option>@endforeach</select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($lines->isEmpty())
        <p class="text-muted">{{ __('warehouse.receiving.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.asns.client') }}</th><th>{{ __('warehouse.receipts.asn_no') }}</th><th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.asns.container_no') }}</th><th class="num">{{ __('warehouse.asns.expected') }}</th><th>{{ __('warehouse.receiving.arrival_date') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($lines as $l)
                <tr>
                    <td>{{ $l->asn->client->name }}</td>
                    <td><a href="{{ route('warehouse.asns.show', $l->asn) }}">{{ $l->asn->asn_no }}</a> <small class="text-muted">{{ $l->asn->warehouse->code }} · {{ __('warehouse.asn_statuses.'.$l->asn->status) }}</small> @if ($l->asn->unplanned)<span class="badge" data-tone="warn">{{ __('warehouse.asns.unplanned_badge') }}</span>@endif</td>
                    <td>{{ $l->consignment_mark }}</td><td>{{ $l->description }}</td><td>{{ $l->container?->container_no }}</td>
                    <td class="num">{{ $l->expected_cartons }}</td>
                    <td>{{ ($l->asn->arrived_at ?? $l->asn->expected_date)?->format('Y-m-d') ?? '—' }}</td>
                    <td>
                        @role('admin|warehouse_supervisor|warehouse_operator')
                            <a role="button" class="secondary" href="{{ route('warehouse.receiving.form', [$l->asn, $l]) }}" style="padding:.2rem .7rem">{{ __('warehouse.asns.receive') }}</a>
                        @endrole
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $lines->links() }}
    @endif
@endsection
