@extends('layouts.app')

@section('title', __('warehouse.outbound.title'))

@section('content')
    <h1>{{ __('warehouse.outbound.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.outbound.hint') }}</small></p>

    <h2>{{ __('warehouse.outbound.ready') }} <small class="text-muted">{{ $ready->count() }}</small></h2>
    @if ($ready->isEmpty())
        <p class="text-muted">{{ $shortages->isEmpty() ? __('warehouse.outbound.empty') : __('warehouse.outbound.ready_empty') }}</p>
    @else
        {{-- Audit 2026-09-22 OUTBOUND-12 (CR #141): 全选 / count on the button / at least one row (server too); client + date filters tick / un-tick rows on the page; 箱数 and a due badge per row. --}}
        @error('order_ids')<p><mark>{{ $message }}</mark></p>@enderror
        <form method="post" action="{{ route('warehouse.outbound.waves.release') }}" id="release-form">
            @csrf
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th><input type="checkbox" id="release-all" checked aria-label="{{ __('warehouse.outbound.select_all') }}" title="{{ __('warehouse.outbound.select_all') }}"></th><th>{{ __('warehouse.outbound.order') }}</th><th>{{ __('warehouse.outbound.client') }}</th><th>{{ __('warehouse.outbound.suburb') }}</th><th>{{ __('warehouse.outbound.requested_date_col') }}</th><th class="num">{{ __('warehouse.outbound.cartons') }}</th><th>{{ __('warehouse.outbound.fulfilment') }}</th></tr></thead>
                <tbody>
                @foreach ($ready as $r)
                    <tr data-client="{{ $r->client_id }}" data-date="{{ $r->requested_date }}">
                        <td><input type="checkbox" name="order_ids[]" value="{{ $r->order_id }}" checked aria-label="{{ $r->order_no }}"></td>
                        <td>{{ $r->order_no }}</td><td>{{ $r->client_name }}</td><td>{{ $r->deliver_to_suburb }}</td>
                        <td>{{ $r->requested_date }} @if ($r->requested_date !== null && (string) $r->requested_date <= $today)<span class="badge" data-tone="danger">{{ __('warehouse.outbound.due_badge') }}</span>@endif</td>
                        <td class="num">{{ $r->cartons }}</td><td>#{{ $r->fulfilment_id }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @role('admin|warehouse_supervisor|warehouse_operator')
                <p class="text-muted"><small>{{ __('warehouse.outbound.filter_hint') }}</small></p>
                <div class="grid">
                    <label>{{ __('warehouse.outbound.client') }}
                        <select name="client_id" id="release-client">
                            <option value="">{{ __('platform.jobs.all') }}</option>
                            @foreach ($ready->unique('client_id') as $c)<option value="{{ $c->client_id }}">{{ $c->client_name }}</option>@endforeach
                        </select>
                    </label>
                    <label>{{ __('warehouse.outbound.requested_date') }}<x-date-field name="requested_date" /></label>
                    <label>{{ __('warehouse.outbound.warehouse') }}<select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($w->id === ($currentWarehouseId ?? $ready->first()->warehouse_id))>{{ $w->code }}</option>@endforeach</select></label>
                    <label>&nbsp;<button type="submit" id="release-submit" data-label="{{ __('warehouse.outbound.release_count') }}">{{ __('warehouse.outbound.release_count', ['count' => $ready->count()]) }}</button></label>
                </div>
            @endrole
        </form>
    @endif

    @if ($shortages->isNotEmpty())
        <h3>{{ __('warehouse.outbound.shortage_title') }} <small class="text-muted">{{ $shortages->count() }}</small></h3>
        <p class="text-muted"><small>{{ __('warehouse.outbound.shortage_hint') }}</small></p>
        <div class="overflow-auto"><table class="dense" id="shortages">
            <thead><tr><th>{{ __('warehouse.outbound.order') }}</th><th>{{ __('warehouse.outbound.client') }}</th><th>{{ __('warehouse.outbound.requested_date_col') }}</th><th>{{ __('warehouse.outbound.shortage_lines') }}</th></tr></thead>
            <tbody>
            @foreach ($shortages as $s)
                <tr>
                    <td><a href="{{ route('orders.show', $s->order_id) }}">{{ $s->order_no }}</a><br><small class="text-muted">{!! \App\Support\Ui\StatusBadge::render('orders.statuses.operational.', $s->status) !!}</small></td>
                    <td>{{ $s->client_name }}</td>
                    <td>{{ $s->requested_date ? \Illuminate\Support\Carbon::parse($s->requested_date)->format('Y-m-d') : '—' }}</td>
                    <td style="white-space:normal">
                        @foreach ($s->lines as $l)
                            <div>{{ __('warehouse.outbound.shortage_line', ['line' => $l->line_id, 'description' => $l->description, 'need' => $l->need, 'available' => $l->available, 'short' => $l->short]) }}
                                @if ($l->asn_no) · <a href="{{ route('warehouse.asns.show', $l->asn_id) }}">{{ $l->asn_no }}</a>@endif</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
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
                    <td>
                        @if (in_array($t->order_id, $cancelledOrders, true))
                            <span class="badge" data-tone="danger">{{ __('warehouse.outbound.order_cancelled_badge') }}</span> <small class="text-muted">{{ __('warehouse.outbound.cancelled_card') }}</small>
                        @else
                            @role('admin|warehouse_supervisor|warehouse_operator')<a role="button" class="secondary" href="{{ route('warehouse.outbound.pack.form', $t->fulfilment_id) }}">{{ __('warehouse.outbound.pack') }}</a>@endrole
                        @endif
                    </td></tr>
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
                        @if (in_array($packages->first()->order_id, $cancelledOrders, true))
                            {{-- Audit 2026-09-22 OUTBOUND-02: a cancelled order is never handed over (OutboundService::dispatch refuses) — the board says so instead of offering the form. --}}
                            <span class="badge" data-tone="danger">{{ __('warehouse.outbound.order_cancelled_badge') }}</span> <small class="text-muted">{{ __('warehouse.outbound.cancelled_card') }}</small>
                        @elseif (in_array($fulfilmentId, $heldFulfilments, true))
                            {{-- Audit 2026-09-10: a financial hold refuses the handover (OutboundService::dispatch) — show it on the board instead of an English refusal after the click. --}}
                            <span class="badge" data-tone="danger">{{ __('platform.exceptions.hold_types.financial') }}</span> <small class="text-muted">{{ __('warehouse.outbound.errors.financial_hold') }}</small>
                        @else
                            @php($shipment = $shipments->get($fulfilmentId))
                            {{-- Audit 2026-09-22 OUTBOUND-03 (CR #141): the shipment behind the batch is shown inline; a booked one is linked automatically, an unbooked one says 未订舱; the typed id stays as an override. --}}
                            <div>
                                @if ($shipment)
                                    <code>{{ $shipment->shipment_no }}</code> {{ $shipment->carrier ?? '—' }} {!! \App\Support\Ui\StatusBadge::render('transport.statuses.', $shipment->status) !!}
                                    @if ($shipment->status !== 'booked')<span class="badge" data-tone="warn">{{ __('warehouse.outbound.not_booked') }}</span>@endif
                                @else
                                    <span class="badge" data-tone="warn">{{ __('warehouse.outbound.no_shipment') }}</span>
                                @endif
                            </div>
                            @role('admin|warehouse_supervisor|warehouse_operator')
                                <form method="post" action="{{ route('warehouse.outbound.dispatch', $fulfilmentId) }}" class="inline">
                                    @csrf
                                    <input type="number" name="pallet_count" min="0" value="{{ $packages->where('package_type', 'pallet')->count() }}" style="width:6rem" aria-label="{{ __('warehouse.outbound.pallet_count') }}">
                                    <select name="handed_to" style="width:9rem" aria-label="{{ __('warehouse.outbound.handed_to') }}">@foreach ($handedTo as $h)<option value="{{ $h }}" @selected($h === ($shipment?->status === 'booked' ? $shipment->handed_to : 'client'))>{{ __('warehouse.handed_to.'.$h) }}</option>@endforeach</select>
                                    @if ($shipment && $shipment->status === 'booked')
                                        <input type="hidden" name="shipment_id" value="{{ $shipment->id }}" class="shipment-id">
                                    @endif
                                    <details class="inline"><summary style="display:inline">{{ __('warehouse.outbound.manual_shipment_id') }}</summary>
                                        <input type="number" name="shipment_id" min="1" placeholder="{{ __('warehouse.outbound.shipment_id') }}" style="width:9rem" aria-label="{{ __('warehouse.outbound.shipment_id') }}" disabled class="shipment-override">
                                    </details>
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

@push('scripts')
<script>
    (() => {
        // 待释放 (OUTBOUND-12): header 全选, live count on the button, no submit with nothing ticked; client / date filters tick the matching rows only.
        const form = document.getElementById('release-form');
        if (form) {
            const boxes = () => Array.from(form.querySelectorAll('tbody input[name="order_ids[]"]'));
            const all = document.getElementById('release-all'), submit = document.getElementById('release-submit');
            const client = document.getElementById('release-client'), date = form.querySelector('input[name="requested_date"]');
            const count = () => {
                const n = boxes().filter(b => b.checked).length;
                if (submit) { submit.textContent = submit.dataset.label.replace(':count', String(n)); submit.disabled = n === 0; }
                if (all) all.checked = n > 0 && n === boxes().length;
            };
            const filter = () => {
                boxes().forEach(b => {
                    const tr = b.closest('tr');
                    const okClient = !client || !client.value || tr.dataset.client === client.value;
                    const okDate = !date || !date.value || (tr.dataset.date && tr.dataset.date <= date.value);
                    b.checked = okClient && okDate;
                });
                count();
            };
            all?.addEventListener('change', () => { boxes().forEach(b => { b.checked = all.checked; }); count(); });
            form.querySelector('tbody').addEventListener('change', count);
            client?.addEventListener('change', filter);
            date?.addEventListener('change', filter);
            form.addEventListener('submit', event => { if (boxes().filter(b => b.checked).length === 0) event.preventDefault(); });
            count();
        }
        // 待发运 (OUTBOUND-03): opening 手动填运单 ID enables the typed id and drops the prefilled one; closing it restores the link.
        document.querySelectorAll('form details').forEach(details => {
            const override = details.querySelector('.shipment-override'), auto = details.closest('form').querySelector('.shipment-id');
            details.addEventListener('toggle', () => { override.disabled = !details.open; if (auto) auto.disabled = details.open; if (details.open) override.focus(); });
        });
    })();
</script>
@endpush
