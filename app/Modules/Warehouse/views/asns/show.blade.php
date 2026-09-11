@extends('layouts.app')

@section('title', $asn->asn_no)

@section('content')
    <p><a href="{{ route('warehouse.asns.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $asn->asn_no }} <span class="badge" data-tone="{{ in_array($asn->status, ['putaway', 'closed']) ? 'ok' : 'warn' }}">{{ __('warehouse.asn_statuses.'.$asn->status) }}</span> @if ($asn->unplanned)<span class="badge" data-tone="{{ $asn->unplanned_confirmed ? 'muted' : 'danger' }}">{{ __('warehouse.asns.unplanned_badge') }}</span>@endif @if ($asn->isClientSubmitted())<span class="badge" data-tone="{{ $asn->isPendingClientConfirmation() ? 'warn' : 'muted' }}">{{ __($asn->isPendingClientConfirmation() ? 'warehouse.asns.client_pending_badge' : 'warehouse.asns.client_confirmed_badge') }}</span>@endif @if ($asn->receiving_completed_at)<span class="badge" data-tone="ok">{{ __('warehouse.asns.receiving_completed_badge') }}</span>@endif @if ($fromOrders)<span class="badge" data-tone="info">{{ __('warehouse.asns.from_orders_badge') }}</span>@endif</h1>
        <p>{{ $asn->client->name }} · <a href="{{ route('platform.jobs.show', $asn->job) }}">{{ $asn->job->job_no }}</a> · {{ $asn->warehouse->code }} · {{ __('warehouse.inbound_types.'.$asn->inbound_type) }} · {{ __('warehouse.asns.expected_date') }}: {{ $asn->expected_date?->format('Y-m-d') ?? '—' }}</p>
    </header>

    @if ($asn->isPendingClientConfirmation())
        <article class="kv-card" id="client-confirmation">
            <strong>{{ __('warehouse.asns.client_pending_badge') }}</strong>
            <p style="margin:.3rem 0">{{ __('warehouse.asns.client_banner', ['client' => $asn->client->name, 'time' => $asn->created_at?->format('Y-m-d H:i'), 'who' => $asn->createdBy?->name ?? __('warehouse.asns.client_api')]) }}</p>
            @error('confirm_client')<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
            @role('admin|customer_service')
                <form method="post" action="{{ route('warehouse.asns.confirm_client', $asn) }}" class="inline">@csrf<button type="submit" style="width:auto">{{ __('warehouse.asns.confirm_client') }}</button></form>
            @else
                <p class="text-muted"><small>{{ __('warehouse.asns.confirm_client_roles') }}</small></p>
            @endrole
        </article>
    @elseif ($asn->isClientSubmitted())
        <p class="text-muted"><small>{{ __('warehouse.asns.client_confirmed_line', ['who' => $asn->clientConfirmedBy?->name ?? '—', 'time' => $asn->client_confirmed_at?->format('Y-m-d H:i')]) }}</small></p>
    @endif

    @role('admin|warehouse_supervisor|warehouse_operator|customer_service')
        <div class="grid">
            @if ($canImportOrders && $orderCandidates !== [])
                <a role="button" href="#import-orders">{{ __('warehouse.asns.import_orders_title') }}</a>
            @endif
            @if ($asn->status === 'booked')
                <form method="post" action="{{ route('warehouse.asns.arrive', $asn) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('warehouse.asns.arrive') }}</button></form>
            @endif
            @if (in_array($asn->status, ['putaway', 'closed'], true) && $asn->lines->contains(fn ($l) => $l->order_line_id === null && $l->received_cartons > 0))
                <form method="post" action="{{ route('warehouse.asns.generate_orders', $asn) }}" class="inline">@csrf<button type="submit">{{ __('warehouse.asns.generate_orders') }}</button></form>
            @endif
            @if ($asn->unplanned && ! $asn->unplanned_confirmed)
                <form method="post" action="{{ route('warehouse.asns.confirm_unplanned', $asn) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('warehouse.asns.confirm_unplanned') }}</button></form>
            @endif
            @role('admin|warehouse_supervisor|warehouse_operator')
                <a role="button" class="secondary" href="{{ route('warehouse.tasks.create', ['asn_id' => $asn->id]) }}">{{ __('warehouse.asns.new_task') }}</a>
                @if ($asn->lines->contains(fn ($l) => $l->stockUnits->isNotEmpty()))<a role="button" class="secondary outline" target="_blank" href="{{ route('warehouse.labels.asn', $asn) }}">{{ __('warehouse.labels.units') }}</a>@endif
            @endrole
        </div>
    @endrole
    @if (session('blocked'))
        <ul>@foreach (session('blocked') as $b)<li><mark>{{ $b['consignment_mark'] ?: '—' }}</mark> · {{ __('warehouse.asns.blocked_reasons.'.$b['reason']) }} ({{ count($b['asn_line_ids']) }}) · {{ __('warehouse.asns.blocked_fix') }} @foreach ($b['asn_line_ids'] as $id)<a href="{{ route('warehouse.asns.lines.delivery.edit', [$asn, $id]) }}">#{{ $id }}</a>@if (! $loop->last), @endif @endforeach</li>@endforeach</ul>
    @endif

    @if ($asn->containers->isNotEmpty())
        <h2>{{ __('warehouse.asns.containers') }}</h2>
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.asns.container_no') }}</th><th>{{ __('warehouse.asns.size') }}</th><th>{{ __('warehouse.asns.unpack_mode') }}</th><th class="num">{{ __('warehouse.asns.gross_weight') }}</th><th class="num">{{ __('warehouse.asns.line_count') }}</th></tr></thead>
            <tbody>@foreach ($asn->containers as $c)<tr><td>{{ $c->container_no }}</td><td>{{ __('warehouse.container_sizes.'.$c->size) }}</td><td>{{ __('warehouse.unpack_modes.'.$c->unpack_mode) }}</td><td class="num">{{ $c->gross_weight_kg }}</td><td class="num">{{ $c->line_count }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    <h2 id="lines">{{ __('warehouse.asns.lines') }}</h2>
    <p class="text-muted"><small>{{ __('warehouse.asns.lines_hint') }}</small></p>
    @if ($asn->lines->isEmpty())
        <p class="text-muted">{{ __('warehouse.asns.no_lines') }}</p>
        <p class="text-muted"><small>{{ __('warehouse.asns.no_lines_hint') }} @if ($canImportOrders)<a href="#import-orders">{{ __('warehouse.asns.import_orders_title') }}</a>@endif</small></p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.asns.order_col') }}</th><th>{{ __('warehouse.asns.container_no') }}</th><th class="num">{{ __('warehouse.asns.expected') }}</th><th class="num">{{ __('warehouse.asns.received') }}</th><th class="num">{{ __('warehouse.asns.damaged') }}</th><th class="num">{{ __('warehouse.asns.units') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($asn->lines as $l)
                <tr>
                    <td>{{ $l->id }}</td><td>{{ $l->consignment_mark }}</td><td>{{ $l->description }} <small class="text-muted">@if ($l->hasCompleteDelivery()){{ $l->deliver_to_name }} · {{ $l->deliver_to_suburb }} {{ $l->deliver_to_state }} {{ $l->deliver_to_postcode }}@elseif (! $l->isOnOrder())<mark>{{ __('warehouse.asns.delivery_incomplete') }}</mark>@else{{ $l->deliver_to_name }}@endif</small></td>
                    <td>@if (isset($orderRefs[$l->id]))<a href="{{ route('orders.show', $orderRefs[$l->id]['order_id']) }}">{{ $orderRefs[$l->id]['order_no'] }}</a>@else<span class="text-muted">—</span>@endif</td><td>{{ $l->container?->container_no }}</td>
                    <td class="num">{{ $l->expected_cartons }}</td><td class="num">{{ $l->received_cartons }}</td><td class="num">{{ $l->damaged_cartons }}</td><td class="num">{{ $l->stockUnits->count() }}</td>
                    <td>
                        @role('admin|warehouse_supervisor|warehouse_operator')
                            @if (in_array($asn->status, ['booked', 'arrived', 'receiving']) && ! $l->isReceived())
                                <a role="button" class="secondary" href="{{ route('warehouse.receiving.form', [$asn, $l]) }}" style="padding:.2rem .7rem">{{ __('warehouse.asns.receive') }}</a>
                            @elseif ($l->isReceived())
                                <span class="text-muted">{{ __('warehouse.asns.received_ok') }}</span>
                            @endif
                        @endrole
                        @role('admin|warehouse_supervisor|warehouse_operator|customer_service')
                            @unless ($l->isOnOrder())
                                <a href="{{ route('warehouse.asns.lines.delivery.edit', [$asn, $l]) }}">{{ __('warehouse.asns.edit_delivery') }}</a>
                            @endunless
                        @endrole
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif


    @if ($canImportOrders)
        <article class="kv-card" id="import-orders">
            <strong>{{ __('warehouse.asns.import_orders_title') }}</strong>
            <p class="text-muted" style="margin:.3rem 0"><small>{{ __('warehouse.asns.import_orders_hint') }}</small></p>
            @foreach (['import_orders', 'order_ids', 'container_no'] as $bag)
                @error($bag)<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
            @endforeach
            @if ($orderCandidates === [])
                <p class="text-muted">{{ __('warehouse.asns.import_orders_none') }}</p>
            @else
                <form method="post" action="{{ route('warehouse.asns.import_orders', $asn) }}" id="import-orders-form">
                    @csrf
                    <div class="overflow-auto"><table class="dense">
                        <thead><tr><th><input type="checkbox" id="import-orders-all" aria-label="{{ __('warehouse.asns.import_orders_select_all') }}" title="{{ __('warehouse.asns.import_orders_select_all') }}"></th><th>{{ __('warehouse.asns.import_orders_fields.order_no') }}</th><th>{{ __('warehouse.asns.import_orders_fields.job') }}</th><th>{{ __('warehouse.asns.import_orders_fields.mark') }}</th><th>{{ __('warehouse.asns.import_orders_fields.deliver_to') }}</th><th>{{ __('warehouse.asns.import_orders_fields.requested_date') }}</th><th class="num">{{ __('warehouse.asns.import_orders_fields.lines') }}</th><th class="num">{{ __('warehouse.asns.import_orders_fields.cartons') }}</th><th>{{ __('warehouse.asns.import_orders_fields.status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($orderCandidates as $c)
                            @php($blocked = in_array($c['job_id'], $blockedJobs, true))
                            <tr>
                                <td><input type="checkbox" name="order_ids[]" value="{{ $c['order_id'] }}" aria-label="{{ $c['order_no'] }}"{{ ! $blocked && in_array($c['order_id'], old('order_ids', []), false) ? ' checked' : '' }}{{ $blocked ? ' disabled' : '' }}></td>
                                <td><a href="{{ route('orders.show', $c['order_id']) }}">{{ $c['order_no'] }}</a></td>
                                <td>{{ $c['job_no'] }} @if ($blocked)<mark>{{ __('warehouse.asns.import_orders_job_has_asn') }}</mark>@endif</td>
                                <td>{{ $c['consignment_mark'] ?: __('orders.not_provided') }}</td>
                                <td>{{ $c['deliver_to_name'] ?? '—' }} <small class="text-muted">{{ $c['deliver_to_suburb'] }} {{ $c['deliver_to_state'] }}</small></td>
                                <td>{{ $c['requested_date'] ?? '—' }}</td>
                                <td class="num">{{ $c['unlinked_lines'] }} / {{ $c['total_lines'] }}</td>
                                <td class="num">{{ $c['unlinked_cartons'] }}</td>
                                <td>{!! \App\Support\Ui\StatusBadge::render('orders.statuses.operational.', $c['operational_status']) !!}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    @if ($asn->containers->count() > 1)
                        <label>{{ __('warehouse.asns.import_orders_container') }}
                            <select name="container_no" required><option value="">—</option>@foreach ($asn->containers as $c)<option value="{{ $c->container_no }}" @selected(old('container_no') === $c->container_no)>{{ $c->container_no }}</option>@endforeach</select>
                        </label>
                    @endif
                    <button type="submit" style="width:auto">{{ __('warehouse.asns.import_orders_button') }}</button>
                </form>
                <script>
                    (function () {
                        var all = document.getElementById('import-orders-all');
                        var boxes = Array.prototype.slice.call(document.querySelectorAll('#import-orders-form input[name="order_ids[]"]:not([disabled])'));
                        all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); });
                    })();
                </script>
            @endif
        </article>
    @endif

    <h2>{{ __('warehouse.asns.receipts') }}</h2>
    @include('warehouse::receipts._batches', ['asn' => $asn, 'batches' => $receipts, 'rollup' => $rollup])
    @if (in_array($asn->status, ['booked', 'arrived', 'receiving']))
        @if ($asn->lines->isEmpty())
            <p><mark>{{ __('warehouse.asns.receipts_need_lines') }}</mark> @if ($canImportOrders)<a href="#import-orders">{{ __('warehouse.asns.import_orders_title') }}</a>@endif</p>
        @elseif ($asn->lines->contains(fn ($l) => ! $l->isReceived()))
            @role('admin|warehouse_supervisor|warehouse_operator')
                <p>
                    <a role="button" href="{{ route('warehouse.receiving.bulk_form', $asn) }}">{{ __('warehouse.asns.receipts_cta') }}</a>
                    <a role="button" class="secondary outline" href="#lines">{{ __('warehouse.asns.receipts_cta_lines') }}</a>
                </p>
                <p class="text-muted"><small>{{ __('warehouse.asns.receipts_cta_hint') }}</small></p>
            @else
                <p class="text-muted"><small>{{ __('warehouse.asns.receipts_roles_hint') }}</small></p>
            @endrole
        @endif
    @endif

    @role('admin|warehouse_supervisor|warehouse_operator|customer_service')
        @if (in_array($asn->status, ['booked', 'arrived', 'receiving']))
            <div class="grid">
                <article>
                    <header>{{ __('warehouse.asns.import') }}</header>
                    <form method="post" action="{{ route('warehouse.asns.import', $asn) }}" enctype="multipart/form-data">
                        @csrf
                        <label>{{ __('warehouse.asns.import_file') }}<input type="file" name="file" accept=".xlsx,.xls,.csv" required></label>
                        @if ($asn->containers->isNotEmpty())
                            <label>{{ __('warehouse.asns.import_container') }}<select name="container_no"><option value="">—</option>@foreach ($asn->containers as $c)<option value="{{ $c->container_no }}">{{ $c->container_no }}</option>@endforeach</select></label>
                        @endif
                        <button type="submit" class="secondary">{{ __('warehouse.asns.import_button') }}</button>
                    </form>
                </article>
                <article>
                    @php($manualOpen = old('description') !== null || $errors->hasAny(['consignment_mark', 'description', 'expected_cartons', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference']))
                    <details id="manual-line"{{ $manualOpen ? ' open' : '' }}>
                    <summary>{{ __('warehouse.asns.manual_add_summary') }}</summary>
                    @foreach (['description', 'expected_cartons', 'consignment_mark', 'deliver_to_name', 'deliver_to_phone', 'deliver_to_address', 'deliver_to_suburb', 'deliver_to_state', 'deliver_to_postcode', 'fba_reference'] as $field)
                        @error($field)<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
                    @endforeach
                    <form method="post" action="{{ route('warehouse.asns.lines.store', $asn) }}">
                        @csrf
                        <div class="grid">
                            <input type="text" name="consignment_mark" placeholder="{{ __('warehouse.stock.mark') }}" value="{{ old('consignment_mark') }}">
                            <input type="text" name="description" placeholder="{{ __('warehouse.stock.description') }}" value="{{ old('description') }}" required>
                            <input type="number" name="expected_cartons" min="0" placeholder="{{ __('warehouse.asns.expected') }}" value="{{ old('expected_cartons', 1) }}" required>
                        </div>
                        <div class="grid">
                            @if ($asn->containers->isNotEmpty())<select name="container_no"><option value="">{{ __('warehouse.asns.container_no') }}</option>@foreach ($asn->containers as $c)<option value="{{ $c->container_no }}">{{ $c->container_no }}</option>@endforeach</select>@endif
                            <input type="text" name="deliver_to_name" placeholder="{{ __('warehouse.asns.delivery_fields.deliver_to_name') }}" value="{{ old('deliver_to_name') }}">
                            <input type="text" name="deliver_to_phone" placeholder="{{ __('warehouse.asns.delivery_fields.deliver_to_phone') }}" value="{{ old('deliver_to_phone') }}" maxlength="40">
                            <input type="text" name="fba_reference" placeholder="{{ __('warehouse.asns.delivery_fields.fba_reference') }}" value="{{ old('fba_reference') }}" maxlength="60">
                        </div>
                        <div class="grid">
                            <input type="text" name="deliver_to_address" placeholder="{{ __('warehouse.asns.delivery_fields.deliver_to_address') }}" value="{{ old('deliver_to_address') }}">
                            <input type="text" name="deliver_to_suburb" placeholder="{{ __('warehouse.asns.delivery_fields.deliver_to_suburb') }}" value="{{ old('deliver_to_suburb') }}" maxlength="100">
                            <select name="deliver_to_state"><option value="">{{ __('warehouse.asns.delivery_fields.deliver_to_state') }}</option>@foreach (\App\Support\Enums::STATES as $state)<option value="{{ $state }}" @selected(old('deliver_to_state') === $state)>{{ $state }}</option>@endforeach</select>
                            <input type="text" name="deliver_to_postcode" placeholder="{{ __('warehouse.asns.delivery_fields.deliver_to_postcode') }}" value="{{ old('deliver_to_postcode') }}" maxlength="10">
                        </div>
                        <p class="text-muted"><small>{{ __('warehouse.asns.delivery_hint') }}</small></p>
                        <button type="submit" class="secondary">{{ __('platform.common.save') }}</button>
                    </form>
                    </details>
                </article>
            </div>
        @endif
    @endrole

    @if ($imports->isNotEmpty())
        <h3>{{ __('warehouse.asns.imports') }}</h3>
        <ul>@foreach ($imports as $i)<li>{{ $i->created_at->format('Y-m-d H:i') }} · {!! \App\Support\Ui\StatusBadge::render('warehouse.asns.import_statuses.', $i->status) !!} · {{ __('warehouse.asns.imported', ['rows' => $i->row_count, 'errors' => $i->error_count, 'warnings' => count($i->warnings ?? [])]) }}</li>@endforeach</ul>
    @endif

    @if ($tasks->isNotEmpty())
        <h3>{{ __('warehouse.asns.tasks') }}</h3>
        <table class="dense"><thead><tr><th>{{ __('warehouse.tasks.task_no') }}</th><th>{{ __('warehouse.tasks.type') }}</th><th>{{ __('warehouse.tasks.status') }}</th><th class="num">{{ __('warehouse.tasks.billable_qty') }}</th></tr></thead>
        <tbody>@foreach ($tasks as $t)<tr><td>{{ $t->task_no }}</td><td>{{ __('warehouse.task_types.'.$t->task_type) }}</td><td>{!! \App\Support\Ui\StatusBadge::render('warehouse.task_statuses.', $t->status) !!}</td><td class="num">{{ $t->billable_qty }} {{ $t->billable_uom ? __('warehouse.uoms.'.$t->billable_uom) : '' }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
