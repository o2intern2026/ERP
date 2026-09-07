@extends('layouts.app')

@section('title', __('warehouse.returns.title'))

@section('content')
    <h1>{{ __('warehouse.returns.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.returns.hint') }}</small></p>

    @role('admin|warehouse_supervisor|warehouse_operator')
        <article>
            <header>{{ __('warehouse.returns.open') }}</header>
            <form method="post" action="{{ route('warehouse.returns.store') }}" class="grid">
                @csrf
                <input type="text" name="order_no" class="scan" placeholder="{{ __('warehouse.returns.order_no') }}" value="{{ old('order_no') }}" required>
                <select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($w->id === $currentWarehouseId)>{{ $w->code }}</option>@endforeach</select>
                <input type="text" name="notes" placeholder="{{ __('warehouse.returns.notes') }}" value="{{ old('notes') }}">
                <button type="submit">{{ __('warehouse.returns.open') }}</button>
            </form>
            @error('order_no')<p><mark>{{ $message }}</mark></p>@enderror
        </article>
    @endrole

    @if ($receipts->isEmpty())
        <p class="text-muted">{{ __('warehouse.returns.empty') }}</p>
    @else
        @php($nos = $orderNos($receipts->pluck('original_order_id')))
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.returns.receipt_no') }}</th><th>{{ __('warehouse.returns.client') }}</th><th>{{ __('warehouse.returns.original_order') }}</th><th>{{ __('warehouse.returns.warehouse') }}</th><th>{{ __('warehouse.returns.status') }}</th><th class="num">{{ __('warehouse.returns.lines') }}</th><th>{{ __('warehouse.returns.received_at') }}</th></tr></thead>
            <tbody>
            @foreach ($receipts as $r)
                <tr><td><a href="{{ route('warehouse.returns.show', $r) }}">{{ $r->receipt_no }}</a></td><td>{{ $r->client->name }}</td><td>{{ $nos[$r->original_order_id] ?? '#'.$r->original_order_id }}</td><td>{{ $r->warehouse->code }}</td><td><span class="badge" data-tone="{{ in_array($r->status, ['inspected', 'closed'], true) ? 'ok' : 'warn' }}">{{ __('warehouse.return_statuses.'.$r->status) }}</span></td><td class="num">{{ $r->lines_count }}</td><td>{{ $r->received_at?->format('Y-m-d H:i') }}</td></tr>
            @endforeach
            </tbody>
        </table>
        {{ $receipts->links() }}
    @endif
@endsection
