@extends('layouts.app')

@section('title', $stocktake->stocktake_no)

@section('content')
    <p><a href="{{ route('warehouse.stocktakes.index') }}">← {{ __('platform.common.back') }}</a></p>
    @php($canCount = auth()->user()->hasAnyRole(['admin', 'warehouse_supervisor', 'warehouse_operator']))
    @php($uncounted = $lines->reject->isCounted()->count())
    <header>
        <h1>{{ $stocktake->stocktake_no }} <span class="badge" data-tone="{{ $stocktake->status === 'closed' ? 'ok' : 'warn' }}">{{ __('warehouse.stocktakes.statuses.'.$stocktake->status) }}</span></h1>
        <p>{{ $stocktake->warehouse->code }} · {{ $stocktake->location?->full_code ?? __('platform.jobs.all') }} · {{ __('warehouse.stocktakes.progress', ['done' => $lines->count() - $uncounted, 'total' => $lines->count()]) }}</p>
    </header>

    @if ($stocktake->status === 'counting' && $canCount)
        <form method="post" action="{{ route('warehouse.stocktakes.scan', $stocktake) }}" class="grid">
            @csrf
            <input type="text" name="code" class="scan" placeholder="{{ __('warehouse.stocktakes.scan_code') }}" value="{{ old('code') }}" autofocus required>
            <input type="number" name="counted_qty" min="0" placeholder="{{ __('warehouse.stocktakes.scan_qty') }}" value="{{ old('counted_qty') }}">
            <button type="submit" class="secondary">{{ __('warehouse.stocktakes.scan') }}</button>
        </form>
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
                {{-- Audit 2026-09-10: the count form only for roles the POST route accepts; read-only roles see the plain cells. --}}
                @if ($stocktake->status === 'counting' && $canCount)
                    <td colspan="3">
                        <form method="post" action="{{ route('warehouse.stocktakes.count', [$stocktake, $l]) }}" class="inline">
                            @csrf
                            <input type="number" name="counted_qty" min="0" value="{{ $l->counted_qty ?? $l->expected_qty }}" style="width:6rem" required>
                            <input type="text" name="reason" value="{{ $l->reason }}" placeholder="{{ __('warehouse.stocktakes.reason') }}" style="width:16rem">
                            <button type="submit" class="secondary">{{ __('warehouse.stocktakes.record') }}</button>
                            @if ($l->isCounted())<span class="badge" data-tone="{{ $l->variance === 0 ? 'ok' : 'danger' }}">{{ $l->variance > 0 ? '+' : '' }}{{ $l->variance }}</span>@else<span class="badge" data-tone="warn">{{ __('warehouse.stocktakes.not_counted') }}</span>@endif
                        </form>
                    </td>
                @else
                    <td class="num">{{ $l->isCounted() ? $l->counted_qty : '—' }}</td>
                    <td class="num">@if ($l->isCounted())<span class="badge" data-tone="{{ $l->variance === 0 ? 'ok' : 'danger' }}">{{ $l->variance > 0 ? '+' : '' }}{{ $l->variance }}</span>@else<span class="badge" data-tone="warn">{{ __('warehouse.stocktakes.not_counted') }}</span>@endif</td>
                    <td>{{ $l->reason }}</td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($stocktake->status === 'counting')
        @role('admin|warehouse_supervisor')
            {{-- Closing needs every line counted (StocktakeService::close); say so here instead of after the click. --}}
            <form method="post" action="{{ route('warehouse.stocktakes.close', $stocktake) }}">
                @csrf
                <button type="submit" @disabled($uncounted > 0)>{{ __('warehouse.stocktakes.close') }}</button>
                @if ($uncounted > 0)<small class="text-muted">{{ __('warehouse.stocktakes.close_blocked', ['count' => $uncounted]) }}</small>@endif
            </form>
        @endrole
    @endif
@endsection
