@extends('layouts.app')

@section('title', $stocktake->stocktake_no)

@section('content')
    <p><a href="{{ route('warehouse.stocktakes.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $stocktake->stocktake_no }} <span class="badge" data-tone="{{ $stocktake->status === 'closed' ? 'ok' : 'warn' }}">{{ __('warehouse.stocktakes.statuses.'.$stocktake->status) }}</span></h1>
        <p>{{ $stocktake->warehouse->code }} · {{ $stocktake->location?->full_code ?? __('platform.jobs.all') }} · {{ __('warehouse.stocktakes.progress', ['done' => $lines->filter->isCounted()->count(), 'total' => $lines->count()]) }}</p>
    </header>

    @if ($stocktake->status === 'counting')
        @role('admin|warehouse_supervisor|warehouse_operator')
            <form method="post" action="{{ route('warehouse.stocktakes.scan', $stocktake) }}" class="grid">
                @csrf
                <input type="text" name="code" class="scan" placeholder="{{ __('warehouse.stocktakes.scan_code') }}" autofocus required>
                <input type="number" name="counted_qty" min="0" placeholder="{{ __('warehouse.stocktakes.scan_qty') }}">
                <button type="submit" class="secondary">{{ __('warehouse.stocktakes.scan') }}</button>
            </form>
        @endrole
    @endif

    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.location') }}</th><th>{{ __('warehouse.stock.client') }}</th><th>{{ __('warehouse.stock.description') }}</th><th class="num">{{ __('warehouse.stocktakes.expected') }}</th><th class="num">{{ __('warehouse.stocktakes.counted') }}</th><th class="num">{{ __('warehouse.stocktakes.variance') }}</th><th>{{ __('warehouse.stocktakes.reason') }}</th></tr></thead>
        <tbody>
        @foreach ($lines as $l)
            <tr>
                <td><code>{{ $l->stockUnit->label_code }}</code> @if ($l->scanned)<small class="text-muted">📷</small>@endif</td>
                <td>{{ $l->stockUnit->location?->full_code }}</td>
                <td>{{ $l->stockUnit->asnLine->asn->client->name }}</td>
                <td>{{ $l->stockUnit->asnLine->description }}</td>
                <td class="num">{{ $l->expected_qty }}</td>
                @if ($stocktake->status === 'counting')
                    <td colspan="3">
                        <form method="post" action="{{ route('warehouse.stocktakes.count', [$stocktake, $l]) }}" class="inline">
                            @csrf
                            <input type="number" name="counted_qty" min="0" value="{{ $l->counted_qty ?? $l->expected_qty }}" style="width:6rem" required>
                            <input type="text" name="reason" value="{{ $l->reason }}" placeholder="{{ __('warehouse.stocktakes.reason') }}" style="width:16rem">
                            <button type="submit" class="secondary">{{ __('warehouse.stocktakes.record') }}</button>
                            @if ($l->isCounted())<span class="badge" data-tone="{{ $l->variance === 0 ? 'ok' : 'danger' }}">{{ $l->variance > 0 ? '+' : '' }}{{ $l->variance }}</span>@endif
                        </form>
                    </td>
                @else
                    <td class="num">{{ $l->counted_qty }}</td>
                    <td class="num"><span class="badge" data-tone="{{ $l->variance === 0 ? 'ok' : 'danger' }}">{{ $l->variance > 0 ? '+' : '' }}{{ $l->variance }}</span></td>
                    <td>{{ $l->reason }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($stocktake->status === 'counting')
        @role('admin|warehouse_supervisor')
            <form method="post" action="{{ route('warehouse.stocktakes.close', $stocktake) }}">@csrf<button type="submit">{{ __('warehouse.stocktakes.close') }}</button></form>
        @endrole
    @endif
@endsection
