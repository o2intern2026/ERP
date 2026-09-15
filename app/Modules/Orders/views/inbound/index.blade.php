@extends('layouts.app')

@section('title', __('orders.inbound.title'))

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.nav') }}</a></p>
    <header>
        <h1>{{ __('orders.inbound.title') }}</h1>
        <p class="text-muted"><small>{{ __('orders.inbound.hint') }}</small></p>
    </header>

    @if ($errors->any())
        <article role="alert"><strong>{{ __('orders.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <form method="get" class="grid">
        <select name="client_id" aria-label="{{ __('orders.fields.client') }}">
            <option value="">{{ __('orders.filters.all_clients') }}</option>
            @foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>@endforeach
        </select>
        <button type="submit" class="secondary">{{ __('orders.actions.filter') }}</button>
    </form>

    @if ($groups->isEmpty())
        <p class="text-muted">{{ __('orders.inbound.empty') }}</p>
    @else
        <form method="post" action="{{ route('orders.inbound.store') }}" id="inbound-form">
            @csrf
            @foreach ($groups as $clientId => $orders)
                <h2>{{ $orders->first()->client->name }} <small class="text-muted">{{ __('orders.inbound.group_count', ['count' => $orders->count()]) }}</small></h2>
                @if (! empty($submissions[$clientId]))
                    {{-- CHANGE_REQUESTS #123: what the client submitted with its portal 入库清单 — one click ticks that batch and pre-fills the ASN header below.
                         CHANGE_REQUESTS #125: a 需要我们上门提货 request is flagged here and pre-fills the 到仓方式 fields too (the chosen plan is read server side). --}}
                    <article class="kv-card">
                        <strong>{{ __('orders.imports.inbound.title') }}</strong>
                        <p class="text-muted"><small>{{ __('orders.imports.inbound.hint') }}</small></p>
                        <div class="overflow-auto"><table class="dense">
                            <thead><tr>
                                <th>#</th><th>{{ __('orders.imports.inbound.container_no') }}</th><th>{{ __('orders.imports.inbound.container_size') }}</th><th>{{ __('orders.imports.inbound.expected_date') }}</th>
                                <th>{{ __('orders.imports.inbound.reference') }}</th><th>{{ __('orders.imports.inbound.notes') }}</th><th>{{ __('orders.imports.inbound.uploaded_at') }}</th><th>{{ __('orders.imports.inbound.file') }}</th>
                                <th>{{ __('orders.imports.inbound.transport') }}</th><th>{{ __('orders.imports.inbound.orders') }}</th><th></th>
                            </tr></thead>
                            <tbody>
                            @foreach ($submissions[$clientId] as $s)
                                @php($col = $s['collection'])
                                @php($pickup = is_array($col['address'] ?? null) ? $col['address'] : [])
                                @php($pref = is_array($col['preference'] ?? null) ? $col['preference'] : null)
                                <tr>
                                    <td><a href="{{ route('orders.imports.show', $s['import']) }}">#{{ $s['import']->id }}</a></td>
                                    <td>{{ ($s['inbound']['container_no'] ?? null) ?: '—' }}</td>
                                    <td>@if (! empty($s['inbound']['container_size'])){{ __('warehouse.container_sizes.'.$s['inbound']['container_size']) }}@else — @endif</td>
                                    <td>{{ ($s['inbound']['expected_date'] ?? null) ?: '—' }}</td>
                                    <td>{{ ($s['inbound']['reference'] ?? null) ?: '—' }}</td>
                                    <td>{{ ($s['inbound']['notes'] ?? null) ?: '—' }}</td>
                                    <td>{{ ($s['inbound']['uploaded_at'] ?? null) ?: $s['import']->created_at?->format('Y-m-d H:i') }}</td>
                                    <td>{{ $s['file'] ?: '—' }}</td>
                                    <td>
                                        @if ($col)
                                            <span class="badge" data-tone="warn">{{ __('orders.imports.inbound.collection_badge') }}</span>
                                            <br><small>{{ $pickup['suburb'] ?? '' }} {{ $pickup['state'] ?? '' }} {{ $pickup['postcode'] ?? '' }} · {{ __('orders.imports.inbound.ready_date', ['date' => ($col['ready_date'] ?? null) ?: '—']) }}</small>
                                            <br><small>@if ($pref && filled($pref['source'] ?? null)){{ __('orders.imports.inbound.chosen_plan', ['plan' => ($pref['carrier_name'] ?? null ?: __('transport.sources.'.$pref['source'])).' · '.__('transport.service_levels.'.($pref['service_level'] ?? 'standard')), 'price' => \App\Support\Money::cents((int) ($pref['customer_price_cents'] ?? 0))->format()]) }}@else<span class="text-muted">{{ __('orders.imports.inbound.no_plan') }}</span>@endif</small>
                                        @else
                                            <span class="text-muted">{{ __('orders.imports.inbound.client_delivers') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ implode(', ', $s['order_nos']) }}</td>
                                    <td><button type="button" class="secondary outline" data-select-import data-import-id="{{ $s['import']->id }}" data-orders="{{ implode(',', $s['order_ids']) }}" data-container-no="{{ $s['inbound']['container_no'] ?? '' }}" data-container-size="{{ $s['inbound']['container_size'] ?? '' }}" data-expected-date="{{ $s['inbound']['expected_date'] ?? '' }}" data-notes="{{ $s['inbound']['notes'] ?? '' }}"
                                        @if ($col) data-collection="1" data-warehouse-id="{{ $col['warehouse_id'] ?? '' }}" data-collection-name="{{ $pickup['name'] ?? '' }}" data-collection-phone="{{ $pickup['phone'] ?? '' }}" data-collection-address="{{ $pickup['address'] ?? '' }}" data-collection-suburb="{{ $pickup['suburb'] ?? '' }}" data-collection-state="{{ $pickup['state'] ?? '' }}" data-collection-postcode="{{ $pickup['postcode'] ?? '' }}" data-collection-type="{{ $pickup['type'] ?? 'business' }}" data-collection-ready-date="{{ $col['ready_date'] ?? '' }}" data-collection-notes="{{ $col['notes'] ?? '' }}" @endif>{{ __('orders.imports.inbound.select') }}</button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    </article>
                @endif
                <div class="overflow-auto"><table class="dense">
                    <thead><tr>
                        <th>{{ __('orders.inbound.fields.select') }}</th><th>{{ __('orders.inbound.fields.order_no') }}</th><th>{{ __('orders.inbound.fields.job') }}</th><th>{{ __('orders.inbound.fields.mark') }}</th>
                        <th>{{ __('orders.inbound.fields.deliver_to') }}</th><th>{{ __('orders.inbound.fields.requested_date') }}</th><th class="num">{{ __('orders.inbound.fields.lines') }}</th><th class="num">{{ __('orders.inbound.fields.cartons') }}</th><th>{{ __('orders.inbound.fields.status') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($orders as $order)
                        @php($unlinked = $order->lines->whereNull('asn_line_id'))
                        <tr>
                            <td><input type="checkbox" name="order_ids[]" value="{{ $order->id }}" data-client="{{ $order->client_id }}" aria-label="{{ $order->order_no }}" @checked(in_array($order->id, old('order_ids', $preselected), false))></td>
                            <td><a href="{{ route('orders.show', $order) }}">{{ $order->order_no }}</a>@if (isset($importByOrder[$order->id]))<br><small class="text-muted">{{ __('orders.imports.inbound.import', ['id' => $importByOrder[$order->id]]) }}</small>@endif</td>
                            <td>{{ $order->job->job_no }}</td>
                            <td>{{ $order->consignment_mark ?: __('orders.not_provided') }}</td>
                            <td>{{ $order->deliver_to_name }} <small class="text-muted">{{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }}</small></td>
                            <td>{{ $order->requested_date?->format('Y-m-d') }}</td>
                            <td class="num">{{ $unlinked->count() }} / {{ $order->lines->count() }}</td>
                            <td class="num">{{ $unlinked->sum('carton_qty') }}</td>
                            <td>{!! \App\Support\Ui\StatusBadge::render('orders.statuses.operational.', $order->operational_status) !!}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endforeach

            <article class="kv-card">
                <strong>{{ __('orders.inbound.form_title') }}</strong>
                <p class="text-muted"><small>{{ __('orders.inbound.form_hint') }}</small></p>
                <div class="grid">
                    <label>{{ __('orders.inbound.warehouse') }}
                        <select name="warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $warehouses->first()?->id) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('orders.inbound.inbound_type') }}
                        <select name="inbound_type" id="inbound_type" required>@foreach ($inboundTypes as $t)<option value="{{ $t }}" @selected(old('inbound_type', 'container') === $t)>{{ __('warehouse.inbound_types.'.$t) }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('orders.inbound.expected_date') }}<input type="date" name="expected_date" value="{{ old('expected_date') }}"></label>
                    <label>{{ __('orders.inbound.notes') }}<input type="text" name="notes" maxlength="2000" value="{{ old('notes') }}"></label>
                </div>
                <div class="grid" id="container-fields">
                    <label>{{ __('orders.inbound.container_no') }}<input type="text" name="container_no" maxlength="20" value="{{ old('container_no') }}" placeholder="MSKU1234567" style="text-transform:uppercase"></label>
                    <label>{{ __('orders.inbound.container_size') }}<select name="container_size">@foreach ($containerSizes as $s)<option value="{{ $s }}" @selected(old('container_size', '40') === $s)>{{ __('warehouse.container_sizes.'.$s) }}</option>@endforeach</select></label>
                    <label>{{ __('orders.inbound.unpack_mode') }}<select name="unpack_mode">@foreach ($unpackModes as $m)<option value="{{ $m }}" @selected(old('unpack_mode', 'loose') === $m)>{{ __('warehouse.unpack_modes.'.$m) }}</option>@endforeach</select></label>
                    <label>{{ __('orders.inbound.gross_weight_kg') }}<input type="number" name="gross_weight_kg" min="0" step="0.001" value="{{ old('gross_weight_kg') }}"></label>
                </div>

                {{-- CHANGE_REQUESTS #125: 到仓方式. The 我方上门提货 fieldset is hidden AND disabled otherwise, so nothing of it submits with 客户自送. --}}
                @php($weCollect = old('inbound_transport') === 'we_collect')
                <fieldset id="inbound-transport" style="margin:.6rem 0 0">
                    <legend><strong>{{ __('orders.inbound.collection.title') }}</strong></legend>
                    @foreach (\App\Support\Enums::ASN_INBOUND_TRANSPORTS as $mode)
                        <label style="display:inline-block;margin-right:1.2rem"><input type="radio" name="inbound_transport" value="{{ $mode }}" @checked(($weCollect ? 'we_collect' : 'client_delivers') === $mode)> {{ __('orders.inbound.collection.modes.'.$mode) }}</label>
                    @endforeach
                </fieldset>
                <fieldset id="collection-fields" {{ $weCollect ? '' : 'hidden disabled' }}>
                    <p class="text-muted" style="margin:.2rem 0"><small>{{ __('orders.inbound.collection.hint') }}</small></p>
                    <input type="hidden" name="collection_import_id" value="{{ old('collection_import_id') }}">
                    <p id="collection-import-note" class="text-muted" style="margin:.2rem 0" {{ old('collection_import_id') ? '' : 'hidden' }}><small data-template="{{ __('orders.inbound.collection.from_import', ['id' => '__ID__']) }}" data-partial="{{ __('orders.inbound.collection.partial_import', ['id' => '__ID__']) }}">{{ old('collection_import_id') ? __('orders.inbound.collection.from_import', ['id' => old('collection_import_id')]) : '' }}</small></p>
                    <p id="collection-import-cleared" class="text-muted" style="margin:.2rem 0" hidden><small>{{ __('orders.inbound.collection.import_cleared') }}</small></p>
                    <div class="grid">
                        <label>{{ __('orders.inbound.collection.fields.name') }}<input type="text" name="collection[name]" maxlength="255" value="{{ old('collection.name') }}"></label>
                        <label>{{ __('orders.inbound.collection.fields.phone') }}<input type="text" name="collection[phone]" maxlength="40" value="{{ old('collection.phone') }}"></label>
                        <label>{{ __('orders.inbound.collection.fields.type') }}<select name="collection[type]">@foreach (\App\Support\Enums::ADDRESS_TYPES as $t)<option value="{{ $t }}" @selected(old('collection.type', 'business') === $t)>{{ __('orders.inbound.collection.address_types.'.$t) }}</option>@endforeach</select></label>
                    </div>
                    <div class="grid">
                        <label>{{ __('orders.inbound.collection.fields.address') }}<input type="text" name="collection[address]" maxlength="255" value="{{ old('collection.address') }}"></label>
                        <label>{{ __('orders.inbound.collection.fields.suburb') }}<input type="text" name="collection[suburb]" maxlength="100" value="{{ old('collection.suburb') }}"></label>
                        <label>{{ __('orders.inbound.collection.fields.state') }}<select name="collection[state]"><option value="">—</option>@foreach (\App\Support\Enums::STATES as $state)<option value="{{ $state }}" @selected(old('collection.state') === $state)>{{ $state }}</option>@endforeach</select></label>
                        <label>{{ __('orders.inbound.collection.fields.postcode') }}<input type="text" name="collection[postcode]" maxlength="4" inputmode="numeric" value="{{ old('collection.postcode') }}"></label>
                    </div>
                    <div class="grid">
                        <label>{{ __('orders.inbound.collection.fields.ready_date') }}<input type="date" name="collection_ready_date" value="{{ old('collection_ready_date') }}"></label>
                        <label>{{ __('orders.inbound.collection.fields.notes') }}<input type="text" name="collection_notes" maxlength="2000" value="{{ old('collection_notes') }}"></label>
                    </div>
                </fieldset>
                <button type="submit">{{ __('orders.inbound.submit') }}</button>
            </article>
        </form>

        <script>
            (function () {
                var form = document.getElementById('inbound-form');
                var type = document.getElementById('inbound_type'), box = document.getElementById('container-fields');
                function toggle() { box.hidden = type.value !== 'container'; }
                type.addEventListener('change', toggle);
                toggle();
                // CHANGE_REQUESTS #125: 到仓方式 — the collection fieldset is shown and enabled only for 我方上门提货.
                var modes = Array.prototype.slice.call(form.querySelectorAll('input[name="inbound_transport"]'));
                var collectionBox = document.getElementById('collection-fields');
                function toggleCollection() {
                    var weCollect = modes.some(function (r) { return r.checked && r.value === 'we_collect'; });
                    collectionBox.hidden = !weCollect;
                    collectionBox.disabled = !weCollect;
                }
                modes.forEach(function (r) { r.addEventListener('change', toggleCollection); });
                toggleCollection();
                // One client per ASN: ticking an order greys out the other clients' rows.
                var boxes = Array.prototype.slice.call(document.querySelectorAll('#inbound-form input[name="order_ids[]"]'));
                function limit() {
                    var checked = boxes.filter(function (b) { return b.checked; });
                    var client = checked.length ? checked[0].dataset.client : null;
                    boxes.forEach(function (b) { b.disabled = client !== null && b.dataset.client !== client; });
                }
                // CHANGE_REQUESTS #125 review: the import link covers only that submission's orders (the server refuses others). Ticking another
                // order drops the link (the pickup fields stay); ticking only part of the submission says the client's whole-list plan is not carried.
                var importInput = form.querySelector('[name="collection_import_id"]');
                var importNote = document.getElementById('collection-import-note');
                var importCleared = document.getElementById('collection-import-cleared');
                var importOrders = null;
                function checkImport() {
                    if (!importInput.value || importOrders === null) { return; }
                    var checked = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
                    var note = importNote.querySelector('small');
                    if (checked.some(function (id) { return importOrders.indexOf(id) === -1; })) {
                        importInput.value = '';
                        importOrders = null;
                        importNote.hidden = true;
                        importCleared.hidden = false;
                        return;
                    }
                    var partial = importOrders.some(function (id) { return checked.indexOf(id) === -1; });
                    note.textContent = (partial ? note.dataset.partial : note.dataset.template).replace('__ID__', importInput.value);
                }
                boxes.forEach(function (b) { b.addEventListener('change', function () { limit(); checkImport(); }); });
                limit();
                // CHANGE_REQUESTS #123: 选中并填入 — tick the orders of one portal submission and copy its 柜号 / 柜型 / 预计到港 / 备注 into the ASN header.
                // CHANGE_REQUESTS #125: a collection request also sets 我方上门提货 and fills the pickup fields, the warehouse and the import id.
                Array.prototype.slice.call(document.querySelectorAll('[data-select-import]')).forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var ids = btn.dataset.orders.split(',');
                        boxes.forEach(function (b) { b.disabled = false; b.checked = ids.indexOf(b.value) !== -1; });
                        limit();
                        if (btn.dataset.containerNo) { form.querySelector('[name="container_no"]').value = btn.dataset.containerNo; type.value = 'container'; }
                        if (btn.dataset.containerSize) { form.querySelector('[name="container_size"]').value = btn.dataset.containerSize; }
                        if (btn.dataset.expectedDate) { form.querySelector('[name="expected_date"]').value = btn.dataset.expectedDate; }
                        if (btn.dataset.notes) { form.querySelector('[name="notes"]').value = btn.dataset.notes; }
                        if (btn.dataset.collection === '1') {
                            form.querySelector('input[name="inbound_transport"][value="we_collect"]').checked = true;
                            ['name', 'phone', 'address', 'suburb', 'state', 'postcode', 'type'].forEach(function (key) {
                                form.querySelector('[name="collection[' + key + ']"]').value = btn.dataset['collection' + key.charAt(0).toUpperCase() + key.slice(1)] || (key === 'type' ? 'business' : '');
                            });
                            form.querySelector('[name="collection_ready_date"]').value = btn.dataset.collectionReadyDate || '';
                            form.querySelector('[name="collection_notes"]').value = btn.dataset.collectionNotes || '';
                            if (btn.dataset.warehouseId) { form.querySelector('[name="warehouse_id"]').value = btn.dataset.warehouseId; }
                            if (!btn.dataset.containerNo && type.value === 'container') { type.value = 'loose_truck'; }
                            importInput.value = btn.dataset.importId;
                            importOrders = ids;
                            var note = importNote.querySelector('small');
                            note.textContent = note.dataset.template.replace('__ID__', btn.dataset.importId);
                            importNote.hidden = false;
                            importCleared.hidden = true;
                        } else {
                            importInput.value = '';
                            importOrders = null;
                            importNote.hidden = true;
                            importCleared.hidden = true;
                            form.querySelector('input[name="inbound_transport"][value="client_delivers"]').checked = true;
                        }
                        toggle();
                        toggleCollection();
                        form.querySelector('button[type="submit"]').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                });
            })();
        </script>
    @endif
@endsection
