@extends('layouts.app')

@section('title', $wave->wave_no)

@section('content')
    <p><a href="{{ route('warehouse.outbound.index') }}">← {{ __('platform.common.back') }}</a></p>
    <h1>{{ $wave->wave_no }} <span class="badge" data-tone="{{ $wave->status === 'completed' ? 'ok' : 'warn' }}">{{ __('warehouse.wave_statuses.'.$wave->status) }}</span></h1>
    <p class="text-muted">{{ $wave->warehouse->code }} · {{ __('warehouse.outbound.released_at') }} {{ $wave->released_at?->format('Y-m-d H:i') }} · {{ __('warehouse.outbound.tasks') }} {{ $wave->tasks->count() }}</p>

    @error('close')<p><mark>{{ $message }}</mark></p>@enderror
    @foreach ($wave->tasks as $task)
        {{-- Audit 2026-09-22 OUTBOUND-02: a cancelled order's open task shows the put-back instruction and 关闭任务 (admin / supervisor) instead of the confirm forms. --}}
        @php($orderCancelled = in_array($task->order_id, $cancelledOrders, true))
        @php($openCancelled = $orderCancelled && ! in_array($task->status, ['done', 'cancelled'], true))
        <article>
            <header><strong>{{ $task->task_no }}</strong> · {{ __('warehouse.outbound.order') }} {{ $orderNos[$task->order_id] ?? '#'.$task->order_id }} · {{ __('warehouse.outbound.fulfilment') }} #{{ $task->fulfilment_id }} · <span class="badge" data-tone="{{ $task->status === 'done' ? 'ok' : ($task->status === 'cancelled' ? 'muted' : 'warn') }}">{{ __('warehouse.task_statuses.'.$task->status) }}</span>@if ($orderCancelled) <span class="badge" data-tone="danger">{{ __('warehouse.outbound.order_cancelled_badge') }}</span>@endif</header>
            @if ($openCancelled)
                <p><mark>{{ __('warehouse.outbound.cancelled_card') }}</mark></p>
                <p class="text-muted"><small>{{ __('warehouse.outbound.cancelled_card_hint') }}</small></p>
                @role('admin|warehouse_supervisor')
                    <form method="post" action="{{ route('warehouse.outbound.tasks.close', $task) }}" class="inline">@csrf<button type="submit" class="secondary" style="width:auto">{{ __('warehouse.outbound.close_task') }}</button></form>
                @endrole
            @endif
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th>{{ __('warehouse.outbound.location') }}</th><th>{{ __('warehouse.outbound.unit') }}</th><th>{{ __('warehouse.stock.description') }}</th><th class="num">{{ __('warehouse.outbound.required') }}</th><th class="num">{{ __('warehouse.outbound.picked') }}</th><th>{{ __('warehouse.outbound.confirm_pick') }}</th></tr></thead>
                <tbody>
                @foreach ($task->lines as $line)
                    <tr>
                        <td><strong>{{ $line->location?->full_code ?? '—' }}</strong></td><td><code>{{ $line->stockUnit?->label_code }}</code> {{ $line->stockUnit ? __('warehouse.unit_types.'.$line->stockUnit->unit_type) : '' }}</td><td>{{ $line->stockUnit?->asnLine?->description }}</td>
                        <td class="num">{{ $line->required_qty }}</td><td class="num">{{ $line->confirmed_at ? $line->completed_qty : '—' }}</td>
                        <td>
                            @role('admin|warehouse_supervisor|warehouse_operator')
                                @if ($line->confirmed_at === null && ! $orderCancelled && $task->status !== 'cancelled')
                                    <form method="post" action="{{ route('warehouse.outbound.pick', $line) }}" class="inline">
                                        @csrf
                                        <input type="number" name="picked_qty" min="0" max="{{ $line->required_qty }}" value="{{ $line->required_qty }}" class="scan" style="width:6rem">
                                        <button type="submit">{{ __('warehouse.outbound.confirm_pick') }}</button>
                                    </form>
                                @elseif ($line->confirmed_at !== null)
                                    <small class="text-muted">{{ $line->confirmed_at->format('H:i') }}</small>
                                @else
                                    <small class="text-muted">—</small>
                                @endif
                            @endrole
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($task->status === 'done' && in_array($task->fulfilment_id, $dispatchedFulfilments, true))
                <footer><span class="badge" data-tone="ok">{{ __('warehouse.outbound.dispatched_badge') }}</span></footer>
            @elseif ($task->status === 'done' && in_array($task->fulfilment_id, $packedFulfilments, true))
                <footer><span class="badge" data-tone="ok">{{ __('warehouse.outbound.packed_badge') }}</span> <a href="{{ route('warehouse.outbound.index') }}">{{ __('warehouse.outbound.go_dispatch') }}</a></footer>
            @elseif ($task->status === 'done' && $orderCancelled)
                <footer><span class="badge" data-tone="danger">{{ __('warehouse.outbound.order_cancelled_badge') }}</span> <small class="text-muted">{{ __('warehouse.outbound.cancelled_card') }}</small></footer>
            @elseif ($task->status === 'done' && $task->lines->isNotEmpty())
                @role('admin|warehouse_supervisor|warehouse_operator')<footer><a role="button" class="secondary" href="{{ route('warehouse.outbound.pack.form', $task->fulfilment_id) }}">{{ __('warehouse.outbound.pack') }}</a></footer>@endrole
            @endif
        </article>
    @endforeach
@endsection
