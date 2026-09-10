@extends('layouts.app')

@section('title', __('warehouse.outbound.title'))

@section('content')
    <h1>{{ __('warehouse.outbound.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.outbound.hint') }}</small></p>

    <h2>{{ __('warehouse.outbound.ready') }} <small class="text-muted">{{ $ready->count() }}</small></h2>
    @if ($ready->isEmpty())
        <p class="text-muted">{{ __('warehouse.outbound.empty') }}</p>
    @else
        <form method="post" action="{{ route('warehouse.outbound.waves.release') }}">
            @csrf
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th></th><th>{{ __('warehouse.outbound.order') }}</th><th>{{ __('warehouse.outbound.client') }}</th><th>{{ __('warehouse.outbound.suburb') }}</th><th>{{ __('warehouse.outbound.requested_date') }}</th><th>{{ __('warehouse.outbound.fulfilment') }}</th></tr></thead>
                <tbody>
                @foreach ($ready as $r)
                    <tr><td><input type="checkbox" name="order_ids[]" value="{{ $r->order_id }}" checked></td><td>{{ $r->order_no }}</td><td>{{ $r->client_name }}</td><td>{{ $r->deliver_to_suburb }}</td><td>{{ $r->requested_date }}</td><td>#{{ $r->fulfilment_id }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
            @role('admin|warehouse_supervisor|warehouse_operator')
                <div class="grid">
                    <select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($w->id === ($currentWarehouseId ?? $ready->first()->warehouse_id))>{{ $w->code }}</option>@endforeach</select>
                    <button type="submit">{{ __('warehouse.outbound.release') }}</button>
                </div>
            @endrole
        </form>
    @endif

    <h2>{{ __('warehouse.outbound.picking') }} <small class="text-muted">{{ $picking->count() }}</small></h2>
    @if ($picking->isEmpty())
        <p class="text-muted">{{ __('warehouse.outbound.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.outbound.task') }}</th><th>{{ __('warehouse.outbound.wave') }}</th><th>{{ __('warehouse.outbound.order') }}</th><th>{{ __('warehouse.outbound.lines') }}</th><th>{{ __('warehouse.outbound.status') }}</th></tr></thead>
            <tbody>
            @foreach ($picking as $t)
                <tr><td>{{ $t->task_no }}</td><td>@if ($t->wave)<a href="{{ route('warehouse.outbound.waves.show', $t->wave) }}">{{ $t->wave->wave_no }}</a>@endif</td><td>{{ $orderNos[$t->order_id] ?? '#'.$t->order_id }}</td><td>{{ $t->lines->whereNotNull('confirmed_at')->count() }} / {{ $t->lines->count() }}</td><td><span class="badge" data-tone="warn">{{ __('warehouse.task_statuses.'.$t->status) }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('warehouse.outbound.to_pack') }} <small class="text-muted">{{ $toPack->count() }}</small></h2>
    @if ($toPack->isEmpty())
        <p class="text-muted">{{ __('warehouse.outbound.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.outbound.order') }}</th><th>{{ __('warehouse.outbound.fulfilment') }}</th><th>{{ __('warehouse.outbound.task') }}</th><th class="num">{{ __('warehouse.outbound.picked') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($toPack as $t)
                <tr><td>{{ $orderNos[$t->order_id] ?? '#'.$t->order_id }}</td><td>#{{ $t->fulfilment_id }}</td><td>{{ $t->task_no }}</td><td class="num">{{ $t->lines->sum('completed_qty') }}</td>
                    <td>@role('admin|warehouse_supervisor|warehouse_operator')<a role="button" class="secondary" href="{{ route('warehouse.outbound.pack.form', $t->fulfilment_id) }}">{{ __('warehouse.outbound.pack') }}</a>@endrole</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ __('warehouse.outbound.to_dispatch') }} <small class="text-muted">{{ $toDispatch->count() }}</small></h2>
    @if ($toDispatch->isEmpty())
        <p class="text-muted">{{ __('warehouse.outbound.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.outbound.order') }}</th><th>{{ __('warehouse.outbound.fulfilment') }}</th><th>{{ __('warehouse.outbound.labels') }}</th><th>{{ __('warehouse.outbound.packed_at') }}</th><th>{{ __('warehouse.outbound.dispatch') }}</th></tr></thead>
            <tbody>
            @foreach ($toDispatch as $fulfilmentId => $packages)
                <tr>
                    <td>{{ $orderNos[$packages->first()->order_id] ?? '#'.$packages->first()->order_id }}</td><td>#{{ $fulfilmentId }}</td>
                    <td>@foreach ($packages as $p)<code>{{ $p->carton_label }}</code> {{ __('warehouse.package_types.'.$p->package_type) }} {{ $p->weight_kg }}kg<br>@endforeach</td>
                    <td>{{ $packages->first()->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        @if (in_array($fulfilmentId, $heldFulfilments, true))
                            {{-- Audit 2026-09-10: a financial hold refuses the handover (OutboundService::dispatch) — show it on the board instead of an English refusal after the click. --}}
                            <span class="badge" data-tone="danger">{{ __('platform.exceptions.hold_types.financial') }}</span> <small class="text-muted">{{ __('warehouse.outbound.errors.financial_hold') }}</small>
                        @else
                            @role('admin|warehouse_supervisor|warehouse_operator')
                                <form method="post" action="{{ route('warehouse.outbound.dispatch', $fulfilmentId) }}" class="inline">
                                    @csrf
                                    <input type="number" name="pallet_count" min="0" value="{{ $packages->where('package_type', 'pallet')->count() }}" style="width:6rem" aria-label="{{ __('warehouse.outbound.pallet_count') }}">
                                    <select name="handed_to" style="width:9rem">@foreach ($handedTo as $h)<option value="{{ $h }}">{{ __('warehouse.handed_to.'.$h) }}</option>@endforeach</select>
                                    <input type="number" name="shipment_id" min="1" placeholder="{{ __('warehouse.outbound.shipment_id') }}" style="width:9rem">
                                    <button type="submit">{{ __('warehouse.outbound.dispatch') }}</button>
                                </form>
                            @endrole
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <h2>{{ __('warehouse.outbound.waves') }}</h2>
    @if ($waves->isEmpty())
        <p class="text-muted">{{ __('warehouse.outbound.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.outbound.wave_no') }}</th><th>{{ __('warehouse.outbound.warehouse') }}</th><th>{{ __('warehouse.outbound.status') }}</th><th class="num">{{ __('warehouse.outbound.tasks') }}</th><th>{{ __('warehouse.outbound.released_at') }}</th></tr></thead>
            <tbody>@foreach ($waves as $w)<tr><td><a href="{{ route('warehouse.outbound.waves.show', $w) }}">{{ $w->wave_no }}</a></td><td>{{ $w->warehouse->code }}</td><td><span class="badge" data-tone="{{ $w->status === 'completed' ? 'ok' : 'warn' }}">{{ __('warehouse.wave_statuses.'.$w->status) }}</span></td><td class="num">{{ $w->tasks_count }}</td><td>{{ $w->released_at?->format('Y-m-d H:i') }}</td></tr>@endforeach</tbody>
        </table>
    @endif
@endsection
