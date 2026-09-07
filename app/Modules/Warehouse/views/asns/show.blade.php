@extends('layouts.app')

@section('title', $asn->asn_no)

@section('content')
    <p><a href="{{ route('warehouse.asns.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $asn->asn_no }} <span class="badge" data-tone="{{ in_array($asn->status, ['putaway', 'closed']) ? 'ok' : 'warn' }}">{{ __('warehouse.asn_statuses.'.$asn->status) }}</span> @if ($asn->unplanned)<span class="badge" data-tone="{{ $asn->unplanned_confirmed ? 'muted' : 'danger' }}">{{ __('warehouse.asns.unplanned_badge') }}</span>@endif</h1>
        <p>{{ $asn->client->name }} · <a href="{{ route('platform.jobs.show', $asn->job) }}">{{ $asn->job->job_no }}</a> · {{ $asn->warehouse->code }} · {{ __('warehouse.inbound_types.'.$asn->inbound_type) }} · {{ __('warehouse.asns.expected_date') }}: {{ $asn->expected_date?->format('Y-m-d') ?? '—' }}</p>
    </header>

    @role('admin|warehouse_supervisor|warehouse_operator|customer_service')
        <div class="grid">
            @if ($asn->status === 'booked')
                <form method="post" action="{{ route('warehouse.asns.arrive', $asn) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('warehouse.asns.arrive') }}</button></form>
            @endif
            @if ($asn->unplanned && ! $asn->unplanned_confirmed)
                <form method="post" action="{{ route('warehouse.asns.confirm_unplanned', $asn) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('warehouse.asns.confirm_unplanned') }}</button></form>
            @endif
            @role('admin|warehouse_supervisor|warehouse_operator')
                <a role="button" class="secondary" href="{{ route('warehouse.tasks.create', ['asn_id' => $asn->id]) }}">{{ __('warehouse.asns.new_task') }}</a>
            @endrole
        </div>
    @endrole

    @if ($asn->containers->isNotEmpty())
        <h2>{{ __('warehouse.asns.containers') }}</h2>
        <table class="dense">
            <thead><tr><th>{{ __('warehouse.asns.container_no') }}</th><th>{{ __('warehouse.asns.size') }}</th><th>{{ __('warehouse.asns.unpack_mode') }}</th><th class="num">{{ __('warehouse.asns.gross_weight') }}</th><th class="num">{{ __('warehouse.asns.line_count') }}</th></tr></thead>
            <tbody>@foreach ($asn->containers as $c)<tr><td>{{ $c->container_no }}</td><td>{{ __('warehouse.container_sizes.'.$c->size) }}</td><td>{{ __('warehouse.unpack_modes.'.$c->unpack_mode) }}</td><td class="num">{{ $c->gross_weight_kg }}</td><td class="num">{{ $c->line_count }}</td></tr>@endforeach</tbody>
        </table>
    @endif

    <h2>{{ __('warehouse.asns.lines') }}</h2>
    @if ($asn->lines->isEmpty())
        <p class="text-muted">{{ __('warehouse.asns.no_lines') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.asns.container_no') }}</th><th class="num">{{ __('warehouse.asns.expected') }}</th><th class="num">{{ __('warehouse.asns.received') }}</th><th class="num">{{ __('warehouse.asns.damaged') }}</th><th class="num">{{ __('warehouse.asns.units') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($asn->lines as $l)
                <tr>
                    <td>{{ $l->id }}</td><td>{{ $l->consignment_mark }}</td><td>{{ $l->description }} <small class="text-muted">{{ $l->deliver_to_name }}</small></td><td>{{ $l->container?->container_no }}</td>
                    <td class="num">{{ $l->expected_cartons }}</td><td class="num">{{ $l->received_cartons }}</td><td class="num">{{ $l->damaged_cartons }}</td><td class="num">{{ $l->stockUnits->count() }}</td>
                    <td>
                        @role('admin|warehouse_supervisor|warehouse_operator')
                            @if (in_array($asn->status, ['booked', 'arrived', 'receiving']) && $l->stockUnits->isEmpty())
                                <a href="{{ route('warehouse.receiving.form', [$asn, $l]) }}">{{ __('warehouse.asns.receive') }}</a>
                            @elseif ($l->stockUnits->isNotEmpty())
                                <span class="text-muted">{{ __('warehouse.asns.received_ok') }}</span>
                            @endif
                        @endrole
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
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
                    <header>{{ __('warehouse.asns.add_line') }}</header>
                    <form method="post" action="{{ route('warehouse.asns.lines.store', $asn) }}">
                        @csrf
                        <div class="grid">
                            <input type="text" name="consignment_mark" placeholder="{{ __('warehouse.stock.mark') }}" value="{{ old('consignment_mark') }}">
                            <input type="text" name="description" placeholder="{{ __('warehouse.stock.description') }}" value="{{ old('description') }}" required>
                            <input type="number" name="expected_cartons" min="0" placeholder="{{ __('warehouse.asns.expected') }}" value="{{ old('expected_cartons', 1) }}" required>
                        </div>
                        <div class="grid">
                            @if ($asn->containers->isNotEmpty())<select name="container_no"><option value="">{{ __('warehouse.asns.container_no') }}</option>@foreach ($asn->containers as $c)<option value="{{ $c->container_no }}">{{ $c->container_no }}</option>@endforeach</select>@endif
                            <input type="text" name="deliver_to_name" placeholder="收件人" value="{{ old('deliver_to_name') }}">
                            <input type="text" name="deliver_to_postcode" placeholder="邮编" value="{{ old('deliver_to_postcode') }}" maxlength="10">
                            <input type="text" name="fba_reference" placeholder="FBA" value="{{ old('fba_reference') }}">
                        </div>
                        <button type="submit" class="secondary">{{ __('platform.common.save') }}</button>
                    </form>
                </article>
            </div>
        @endif
    @endrole

    @if ($imports->isNotEmpty())
        <h3>{{ __('warehouse.asns.imports') }}</h3>
        <ul>@foreach ($imports as $i)<li>{{ $i->created_at->format('Y-m-d H:i') }} · {{ $i->status }} · {{ __('warehouse.asns.imported', ['rows' => $i->row_count, 'errors' => $i->error_count, 'warnings' => count($i->warnings ?? [])]) }}</li>@endforeach</ul>
    @endif

    @if ($tasks->isNotEmpty())
        <h3>{{ __('warehouse.asns.tasks') }}</h3>
        <table class="dense"><thead><tr><th>{{ __('warehouse.tasks.task_no') }}</th><th>{{ __('warehouse.tasks.type') }}</th><th>{{ __('warehouse.tasks.status') }}</th><th class="num">{{ __('warehouse.tasks.billable_qty') }}</th></tr></thead>
        <tbody>@foreach ($tasks as $t)<tr><td>{{ $t->task_no }}</td><td>{{ __('warehouse.task_types.'.$t->task_type) }}</td><td>{{ __('warehouse.task_statuses.'.$t->status) }}</td><td class="num">{{ $t->billable_qty }} {{ $t->billable_uom ? __('warehouse.uoms.'.$t->billable_uom) : '' }}</td></tr>@endforeach</tbody></table>
    @endif
@endsection
