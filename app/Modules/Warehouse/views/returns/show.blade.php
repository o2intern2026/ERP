@extends('layouts.app')

@section('title', $receipt->receipt_no)

@section('content')
    <p><a href="{{ route('warehouse.returns.index') }}">← {{ __('platform.common.back') }}</a></p>
    <h1>{{ $receipt->receipt_no }} <span class="badge" data-tone="{{ in_array($receipt->status, ['inspected', 'closed'], true) ? 'ok' : 'warn' }}">{{ __('warehouse.return_statuses.'.$receipt->status) }}</span></h1>
    <p class="text-muted">{{ $receipt->client->name }} · {{ __('warehouse.returns.original_order') }} {{ $orderNo }} · {{ $receipt->warehouse->code }} @if ($receipt->notes)· {{ $receipt->notes }}@endif</p>
    @foreach (['received_qty', 'disposition', 'receipt'] as $field)@error($field)<p><mark>{{ $message }}</mark></p>@enderror @endforeach

    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>#</th><th>{{ __('warehouse.stock.description') }}</th><th class="num">{{ __('warehouse.returns.expected') }}</th><th class="num">{{ __('warehouse.returns.received') }}</th><th>{{ __('warehouse.returns.condition') }}</th><th>{{ __('warehouse.returns.disposition') }}</th><th>{{ __('warehouse.returns.stock_unit') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
        <tbody>
        @foreach ($receipt->lines as $line)
            <tr>
                <td>{{ $loop->iteration }}</td><td>{{ $line->description }}</td><td class="num">{{ $line->expected_qty }}</td>
                <td class="num">{{ $line->received_at ? $line->received_qty : '—' }}</td>
                <td>{{ $line->condition ? __('warehouse.conditions.'.$line->condition) : '—' }}</td>
                <td>{{ $line->disposition ? __('warehouse.dispositions.'.$line->disposition) : '—' }}</td>
                <td>@if ($line->stockUnit)<a href="{{ route('warehouse.stock.show', $line->stockUnit) }}"><code>{{ $line->stockUnit->label_code }}</code></a> {{ $line->stockUnit->location?->full_code }}@endif</td>
                <td>
                    @role('admin|warehouse_supervisor|warehouse_operator')
                        @if ($receipt->status === 'expected')
                            <form method="post" action="{{ route('warehouse.returns.receive', [$receipt, $line]) }}" class="inline">
                                @csrf
                                <input type="number" name="received_qty" min="0" value="{{ $line->received_at ? $line->received_qty : $line->expected_qty }}" class="scan" style="width:6rem">
                                <select name="condition" style="width:8rem">@foreach ($conditions as $c)<option value="{{ $c }}" @selected($c === ($line->condition ?? 'good'))>{{ __('warehouse.conditions.'.$c) }}</option>@endforeach</select>
                                <button type="submit" class="secondary">{{ __('warehouse.returns.receive') }}</button>
                            </form>
                        @elseif ($receipt->status === 'received' && $line->inspected_at === null)
                            <form method="post" action="{{ route('warehouse.returns.inspect', [$receipt, $line]) }}" class="inline">
                                @csrf
                                <select name="disposition" style="width:9rem">@foreach ($dispositions as $d)<option value="{{ $d }}" @selected($d === ($line->condition === 'good' ? 'available' : $line->condition))>{{ __('warehouse.dispositions.'.$d) }}</option>@endforeach</select>
                                <button type="submit" class="secondary">{{ __('warehouse.returns.inspect') }}</button>
                            </form>
                        @endif
                    @endrole
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @role('admin|warehouse_supervisor|warehouse_operator')
        @if ($receipt->status === 'expected')
            <form method="post" action="{{ route('warehouse.returns.complete_receiving', $receipt) }}">@csrf<button type="submit">{{ __('warehouse.returns.complete_receiving') }}</button></form>
        @elseif ($receipt->status === 'received')
            <form method="post" action="{{ route('warehouse.returns.complete_inspection', $receipt) }}">@csrf<button type="submit">{{ __('warehouse.returns.complete_inspection') }}</button></form>
        @endif
    @endrole
@endsection
