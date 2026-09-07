@extends('layouts.app')

@section('title', $wave->wave_no)

@section('content')
    <p><a href="{{ route('warehouse.outbound.index') }}">← {{ __('platform.common.back') }}</a></p>
    <h1>{{ $wave->wave_no }} <span class="badge" data-tone="{{ $wave->status === 'completed' ? 'ok' : 'warn' }}">{{ __('warehouse.wave_statuses.'.$wave->status) }}</span></h1>
    <p class="text-muted">{{ $wave->warehouse->code }} · {{ __('warehouse.outbound.released_at') }} {{ $wave->released_at?->format('Y-m-d H:i') }} · {{ __('warehouse.outbound.tasks') }} {{ $wave->tasks->count() }}</p>

    @foreach ($wave->tasks as $task)
        <article>
            <header><strong>{{ $task->task_no }}</strong> · {{ __('warehouse.outbound.order') }} {{ $orderNos[$task->order_id] ?? '#'.$task->order_id }} · {{ __('warehouse.outbound.fulfilment') }} #{{ $task->fulfilment_id }} · <span class="badge" data-tone="{{ $task->status === 'done' ? 'ok' : 'warn' }}">{{ __('warehouse.task_statuses.'.$task->status) }}</span></header>
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th>{{ __('warehouse.outbound.location') }}</th><th>{{ __('warehouse.outbound.unit') }}</th><th>{{ __('warehouse.stock.description') }}</th><th class="num">{{ __('warehouse.outbound.required') }}</th><th class="num">{{ __('warehouse.outbound.picked') }}</th><th>{{ __('warehouse.outbound.confirm_pick') }}</th></tr></thead>
                <tbody>
                @foreach ($task->lines as $line)
                    <tr>
                        <td><strong>{{ $line->location?->full_code ?? '—' }}</strong></td><td><code>{{ $line->stockUnit?->label_code }}</code> {{ $line->stockUnit ? __('warehouse.unit_types.'.$line->stockUnit->unit_type) : '' }}</td><td>{{ $line->stockUnit?->asnLine?->description }}</td>
                        <td class="num">{{ $line->required_qty }}</td><td class="num">{{ $line->confirmed_at ? $line->completed_qty : '—' }}</td>
                        <td>
                            @role('admin|warehouse_supervisor|warehouse_operator')
                                @if ($line->confirmed_at === null)
                                    <form method="post" action="{{ route('warehouse.outbound.pick', $line) }}" class="inline">
                                        @csrf
                                        <input type="number" name="picked_qty" min="0" max="{{ $line->required_qty }}" value="{{ $line->required_qty }}" class="scan" style="width:6rem">
                                        <button type="submit">{{ __('warehouse.outbound.confirm_pick') }}</button>
                                    </form>
                                @else
                                    <small class="text-muted">{{ $line->confirmed_at->format('H:i') }}</small>
                                @endif
                            @endrole
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($task->status === 'done')
                @role('admin|warehouse_supervisor|warehouse_operator')<footer><a role="button" class="secondary" href="{{ route('warehouse.outbound.pack.form', $task->fulfilment_id) }}">{{ __('warehouse.outbound.pack') }}</a></footer>@endrole
            @endif
        </article>
    @endforeach
@endsection
