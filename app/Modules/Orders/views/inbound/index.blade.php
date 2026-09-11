@extends('layouts.app')

@section('title', __('orders.inbound.title'))

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.nav') }}</a></p>
    <header>
        <h1>{{ __('orders.inbound.title') }}</h1>
        <p class="text-muted"><small>{{ __('orders.inbound.hint') }}</small></p>
    </header>

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
                            <td><a href="{{ route('orders.show', $order) }}">{{ $order->order_no }}</a></td>
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
                <button type="submit">{{ __('orders.inbound.submit') }}</button>
            </article>
        </form>

        <script>
            (function () {
                var type = document.getElementById('inbound_type'), box = document.getElementById('container-fields');
                function toggle() { box.hidden = type.value !== 'container'; }
                type.addEventListener('change', toggle);
                toggle();
                // One client per ASN: ticking an order greys out the other clients' rows.
                var boxes = Array.prototype.slice.call(document.querySelectorAll('#inbound-form input[name="order_ids[]"]'));
                function limit() {
                    var checked = boxes.filter(function (b) { return b.checked; });
                    var client = checked.length ? checked[0].dataset.client : null;
                    boxes.forEach(function (b) { b.disabled = client !== null && b.dataset.client !== client; });
                }
                boxes.forEach(function (b) { b.addEventListener('change', limit); });
                limit();
            })();
        </script>
    @endif
@endsection
