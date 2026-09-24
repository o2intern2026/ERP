@extends('layouts.app')

@section('title', $wave->wave_no)

@section('content')
    <p><a href="{{ route('warehouse.outbound.index') }}">← {{ __('platform.common.back') }}</a></p>
    <h1>{{ $wave->wave_no }} <span class="badge" data-tone="{{ $wave->status === 'completed' ? 'ok' : 'warn' }}">{{ __('warehouse.wave_statuses.'.$wave->status) }}</span></h1>
    <p class="text-muted">{{ $wave->warehouse->code }} · {{ __('warehouse.outbound.released_at') }} {{ $wave->released_at?->format('Y-m-d H:i') }} · {{ __('warehouse.outbound.tasks') }} {{ $wave->tasks->count() }}</p>

    @error('close')<p><mark>{{ $message }}</mark></p>@enderror
    @error('short_reason')<p><mark>{{ $message }}</mark></p>@enderror
    @error('line_ids')<p><mark>{{ $message }}</mark></p>@enderror
    {{-- CHANGE_REQUESTS #152 全部确认拣货: the open lines carry a checkbox bound to this form (form="pick-all"); default all ticked, one post
         confirms them at 应拣数. A short pick is unticked here and confirmed on its own row with 实拣 + reason. --}}
    @php($openLines = $wave->tasks->reject(fn ($t) => in_array($t->order_id, $cancelledOrders, true) || $t->status === 'cancelled')->flatMap(fn ($t) => $t->lines->whereNull('confirmed_at')))
    @role('admin|warehouse_supervisor|warehouse_operator')
        @if ($openLines->isNotEmpty())
            <form method="post" action="{{ route('warehouse.outbound.waves.pick_all', $wave) }}" id="pick-all">
                @csrf
                <article class="kv-card">
                    <strong>{{ __('warehouse.outbound.pick_all.title') }}</strong>
                    <p class="text-muted"><small>{{ __('warehouse.outbound.pick_all.hint') }}</small></p>
                    <p style="margin:0">
                        <button type="button" class="secondary outline" id="pick-select-all" style="padding:.15rem .6rem">{{ __('warehouse.outbound.pick_all.select_all') }}</button>
                        <button type="button" class="secondary outline" id="pick-select-none" style="padding:.15rem .6rem">{{ __('warehouse.outbound.pick_all.select_none') }}</button>
                        <button type="submit" id="pick-all-submit" data-label="{{ __('warehouse.outbound.pick_all.submit') }}">{{ __('warehouse.outbound.pick_all.submit', ['count' => $openLines->count()]) }}</button>
                    </p>
                </article>
            </form>
        @endif
    @endrole
    @foreach ($wave->tasks as $task)
        @php($shortLines = $task->lines->filter(fn ($l) => $l->confirmed_at !== null && $l->completed_qty < $l->required_qty))
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
            @php($taskOpen = ! $openCancelled && $task->status !== 'cancelled' && $task->lines->whereNull('confirmed_at')->isNotEmpty())
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th>@if ($taskOpen)<input type="checkbox" class="pick-task-all" checked aria-label="{{ __('warehouse.outbound.pick_all.select_task') }}" title="{{ __('warehouse.outbound.pick_all.select_task') }}">@endif</th><th>{{ __('warehouse.outbound.location') }}</th><th>{{ __('warehouse.outbound.unit') }}</th><th>{{ __('warehouse.stock.description') }}</th><th class="num">{{ __('warehouse.outbound.required') }}</th><th class="num">{{ __('warehouse.outbound.picked') }}</th><th>{{ __('warehouse.outbound.confirm_pick') }}</th></tr></thead>
                <tbody>
                @foreach ($task->lines as $line)
                    <tr>
                        <td>@if ($taskOpen && $line->confirmed_at === null)<input type="checkbox" name="line_ids[]" value="{{ $line->id }}" form="pick-all" class="pick-line" checked aria-label="{{ $line->stockUnit?->label_code ?? $line->id }}">@endif</td>
                        <td><strong>{{ $line->location?->full_code ?? '—' }}</strong></td><td><code>{{ $line->stockUnit?->label_code }}</code> {{ $line->stockUnit ? __('warehouse.unit_types.'.$line->stockUnit->unit_type) : '' }}</td><td>{{ $line->stockUnit?->asnLine?->description }}</td>
                        <td class="num">{{ $line->required_qty }}</td>
                        <td class="num">{{ $line->confirmed_at ? $line->completed_qty : '—' }}
                            {{-- Audit 2026-09-22 OUTBOUND-08 (CR #141): a short pick is visible on its row, not plain "15 / 14". --}}
                            @if ($line->confirmed_at !== null && $line->completed_qty < $line->required_qty)<br><span class="badge" data-tone="warn">{{ __('warehouse.outbound.short_badge', ['short' => $line->required_qty - $line->completed_qty]) }}</span>@endif
                        </td>
                        <td>
                            @role('admin|warehouse_supervisor|warehouse_operator')
                                @if ($line->confirmed_at === null && ! $orderCancelled && $task->status !== 'cancelled')
                                    {{-- Short pick: the reason select + note appear when 实拣 < 应拣 and a confirm() names the shortfall before it is posted (OUTBOUND-08). --}}
                                    <form method="post" action="{{ route('warehouse.outbound.pick', $line) }}" class="inline pick-form" data-required="{{ $line->required_qty }}" data-confirm="{{ __('warehouse.outbound.short_confirm') }}" data-confirm-frozen="{{ __('warehouse.outbound.short_confirm_frozen') }}">
                                        @csrf
                                        <input type="number" name="picked_qty" min="0" max="{{ $line->required_qty }}" value="{{ $line->required_qty }}" class="scan" style="width:6rem" aria-label="{{ __('warehouse.outbound.picked') }}">
                                        <span class="short-fields" hidden>
                                            <select name="short_reason" style="width:9rem" aria-label="{{ __('warehouse.outbound.short_reason') }}"><option value="">{{ __('warehouse.outbound.short_reason') }}</option>@foreach (\App\Modules\Warehouse\Services\OutboundService::SHORT_REASONS as $r)<option value="{{ $r }}">{{ __('warehouse.outbound.short_reasons.'.$r) }}</option>@endforeach</select>
                                            <input type="text" name="short_note" maxlength="255" placeholder="{{ __('warehouse.outbound.short_note') }}" style="width:10rem" aria-label="{{ __('warehouse.outbound.short_note') }}">
                                        </span>
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
                @role('admin|warehouse_supervisor|warehouse_operator')<footer>@if ($shortLines->isNotEmpty())<span class="badge" data-tone="warn">{{ __('warehouse.outbound.short_card_badge', ['lines' => $shortLines->count(), 'short' => $shortLines->sum(fn ($l) => $l->required_qty - $l->completed_qty)]) }}</span> @endif<a role="button" class="secondary" href="{{ route('warehouse.outbound.pack.form', $task->fulfilment_id) }}">{{ __('warehouse.outbound.pack') }}</a></footer>@endrole
            @endif
        </article>
    @endforeach
@endsection

@push('scripts')
<script>
    (() => {
        // CHANGE_REQUESTS #152: 全选 / 取消 / per-task header tick and the live count on the 全部确认 button; nothing ticked → no submit.
        const pickAll = document.getElementById('pick-all');
        if (pickAll) {
            const lines = () => Array.from(document.querySelectorAll('input.pick-line'));
            const submit = document.getElementById('pick-all-submit');
            const sync = () => {
                const n = lines().filter(b => b.checked).length;
                submit.textContent = submit.dataset.label.replace(':count', String(n)); submit.disabled = n === 0;
                document.querySelectorAll('input.pick-task-all').forEach(head => {
                    const own = Array.from(head.closest('table').querySelectorAll('input.pick-line'));
                    const on = own.filter(b => b.checked).length;
                    head.checked = own.length > 0 && on === own.length; head.indeterminate = on > 0 && on < own.length;
                });
            };
            const setAll = (on, scope) => { (scope || lines()).forEach(b => { b.checked = on; }); sync(); };
            document.getElementById('pick-select-all')?.addEventListener('click', () => setAll(true));
            document.getElementById('pick-select-none')?.addEventListener('click', () => setAll(false));
            document.querySelectorAll('input.pick-task-all').forEach(head => head.addEventListener('change', () => setAll(head.checked, Array.from(head.closest('table').querySelectorAll('input.pick-line')))));
            lines().forEach(b => b.addEventListener('change', sync));
            pickAll.addEventListener('submit', event => { if (lines().filter(b => b.checked).length === 0) event.preventDefault(); });
            sync();
        }
        // Short pick (OUTBOUND-08): reason + note appear as soon as 实拣 < 应拣; the submit asks once, naming the shortfall, and the server requires the reason too.
        document.querySelectorAll('form.pick-form').forEach(form => {
            const qty = form.querySelector('input[name="picked_qty"]'), fields = form.querySelector('.short-fields'), reason = form.querySelector('select[name="short_reason"]');
            const required = Number(form.dataset.required);
            const short = () => required - (Number(qty.value) || 0);
            const sync = () => { const s = short() > 0; fields.hidden = !s; reason.required = s; if (!s) reason.value = ''; };
            qty.addEventListener('input', sync); sync();
            form.addEventListener('submit', event => {
                if (short() <= 0) return;
                // CR #142: 找不到 freezes the shortfall and opens a 差异盘点 line instead of returning it to available — say so before the submit.
                const template = reason.value === 'not_found' && form.dataset.confirmFrozen ? form.dataset.confirmFrozen : form.dataset.confirm;
                const text = template.replace(':required', String(required)).replace(':picked', String(Number(qty.value) || 0)).replace(':short', String(short()));
                if (!confirm(text)) event.preventDefault();
            });
        });
    })();
</script>
@endpush
