@extends('layouts.app')

@section('title', __('warehouse.stocktakes.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('warehouse.stocktakes.title') }}</h1>
        @role('admin|warehouse_supervisor|warehouse_operator')<p style="text-align:right"><a role="button" href="{{ route('warehouse.stocktakes.create') }}">{{ __('warehouse.stocktakes.create') }}</a></p>@endrole
    </header>
    @if ($stocktakes->isEmpty())
        <p class="text-muted">{{ __('warehouse.stocktakes.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.stocktakes.no') }}</th><th>{{ __('warehouse.asns.warehouse') }}</th><th>{{ __('warehouse.stocktakes.scope') }}</th><th class="num">{{ __('warehouse.stocktakes.lines') }}</th><th>{{ __('warehouse.stocktakes.status') }}</th><th>{{ __('platform.jobs.created_at') }}</th></tr></thead>
            <tbody>
            @foreach ($stocktakes as $s)
                <tr><td><a href="{{ route('warehouse.stocktakes.show', $s) }}">{{ $s->stocktake_no }}</a></td><td>{{ $s->warehouse->code }}</td><td>{{ $s->location?->full_code ?? ($s->client_id ? __('warehouse.stock.client').' #'.$s->client_id : __('platform.jobs.all')) }}</td><td class="num">{{ $s->lines_count }}</td><td><span class="badge" data-tone="{{ $s->status === 'closed' ? 'ok' : 'warn' }}">{{ __('warehouse.stocktakes.statuses.'.$s->status) }}</span></td><td>{{ $s->created_at->format('Y-m-d H:i') }}</td></tr>
            @endforeach
            </tbody>
        </table>
        {{ $stocktakes->links() }}
    @endif
@endsection
