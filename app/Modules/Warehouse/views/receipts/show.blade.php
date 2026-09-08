@extends('layouts.app')

@section('title', $receipt->receipt_no)

@section('content')
    <p><a href="{{ route('warehouse.receipts.index') }}">← {{ __('warehouse.receipts.title') }}</a> · <a href="{{ route('warehouse.asns.show', $asn) }}">{{ __('warehouse.receipts.asn_no') }} {{ $asn->asn_no }}</a></p>
    <header>
        <h1>{{ $receipt->receipt_no }} <span class="badge" data-tone="{{ $receipt->isOpen() ? 'warn' : 'ok' }}">{{ __('warehouse.receipt_statuses.'.$receipt->status) }}</span> @if ($receipt->unplanned)<span class="badge" data-tone="{{ $asn->unplanned_confirmed ? 'muted' : 'danger' }}">{{ __('warehouse.receipts.pdf.unplanned_badge') }}</span>@endif</h1>
        <p>{{ $receipt->client->name }} · {{ $receipt->warehouse->code }} · @if ($receipt->job)<a href="{{ route('platform.jobs.show', $receipt->job) }}">{{ $receipt->job->job_no }}</a> · @endif{{ __('warehouse.inbound_types.'.$asn->inbound_type) }} · {{ __('warehouse.receipts.batch') }} {{ $receipt->batch_no }}/{{ $batches->count() }}</p>
    </header>
    @error('complete')<p><mark>{{ $message }}</mark></p>@enderror

    <dl class="kv kv-2">
        <dt>{{ __('warehouse.receipts.opened_at') }}</dt><dd>{{ $receipt->opened_at?->format('Y-m-d H:i') }} @if ($receipt->openedBy)· {{ $receipt->openedBy->name }}@endif</dd>
        <dt>{{ __('warehouse.receipts.completed_at') }}</dt><dd>{{ $receipt->completed_at?->format('Y-m-d H:i') ?? '—' }} @if ($receipt->completedBy)· {{ $receipt->completedBy->name }}@endif</dd>
        <dt>{{ __('warehouse.receipts.delivery_reference') }}</dt><dd>{{ $receipt->delivery_reference ?? '—' }}</dd>
        <dt>{{ __('warehouse.receipts.notes') }}</dt><dd>{{ $receipt->notes ?? '—' }}</dd>
        @if ($receipt->pdfDocument)
            <dt>{{ __('warehouse.receipts.stored_pdf') }}</dt><dd><a href="{{ route('platform.documents.download', $receipt->pdfDocument) }}">{{ $receipt->pdfDocument->original_name }}</a></dd>
        @endif
    </dl>

    <div class="grid">
        <a role="button" class="secondary outline" target="_blank" href="{{ route('warehouse.receipts.pdf', $receipt) }}">{{ __('warehouse.receipts.pdf_button') }}</a>
        @role('admin|warehouse_supervisor|warehouse_operator')
            @if ($receipt->isOpen() && $receipt->lines->isNotEmpty())
                <form method="post" action="{{ route('warehouse.receipts.complete', $receipt) }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
                    @csrf
                    <textarea name="notes" rows="2" placeholder="{{ __('warehouse.receipts.notes_placeholder') }}">{{ old('notes') }}</textarea>
                    <button type="submit">{{ __('warehouse.receipts.complete') }}</button>
                    <small class="text-muted">{{ __('warehouse.receipts.complete_hint') }}</small>
                </form>
            @elseif ($receipt->isOpen())
                <p class="text-muted">{{ __('warehouse.receipts.no_lines_yet') }}</p>
            @endif
        @endrole
    </div>

    <h2>{{ __('warehouse.receipts.lines_title') }}</h2>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>#</th><th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.asns.container_no') }}</th><th class="num">{{ __('warehouse.receipts.expected') }}</th><th class="num">{{ __('warehouse.receipts.received') }}</th><th class="num">{{ __('warehouse.receipts.damaged') }}</th><th class="num">{{ __('warehouse.receipts.variance') }}</th><th>{{ __('warehouse.receipts.variance_reason') }}</th><th class="num">{{ __('warehouse.receipts.pallets') }}</th><th>{{ __('warehouse.receipts.unit_labels') }}</th><th>{{ __('warehouse.receipts.received_at') }}</th></tr></thead>
        <tbody>
        @foreach ($receipt->lines as $l)
            <tr>
                <td>{{ $l->asn_line_id }}</td><td>{{ $l->asnLine?->consignment_mark }}</td><td>{{ $l->asnLine?->description }}</td><td>{{ $l->asnLine?->container?->container_no }}</td>
                <td class="num">{{ $l->expected_cartons }}</td><td class="num">{{ $l->received_cartons }}</td><td class="num">{{ $l->damaged_cartons }}</td>
                <td class="num">{{ $l->variance() > 0 ? '+' : '' }}{{ $l->variance() }}</td><td>{{ $l->variance_reason }}</td><td class="num">{{ $l->pallet_count }}</td>
                <td>@foreach ($labels->get($l->asn_line_id, collect()) as $u)<a href="{{ route('warehouse.stock.show', $u) }}"><code>{{ $u->label_code }}</code></a>@if (! $loop->last), @endif @endforeach</td>
                <td>{{ $l->received_at?->format('Y-m-d H:i') }} @if ($l->receivedBy)· {{ $l->receivedBy->name }}@endif</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><th colspan="4">{{ __('warehouse.receipts.totals') }}</th><th class="num">{{ $receipt->lines->sum('expected_cartons') }}</th><th class="num">{{ $receipt->lines->sum('received_cartons') }}</th><th class="num">{{ $receipt->lines->sum('damaged_cartons') }}</th><th class="num">{{ $receipt->lines->sum(fn ($l) => $l->variance()) }}</th><th></th><th class="num">{{ $receipt->lines->sum('pallet_count') }}</th><th colspan="2">{{ $receipt->lines->sum('unit_count') }} {{ __('warehouse.receipts.units_suffix') }}</th></tr></tfoot>
    </table></div>

    <h2>{{ __('warehouse.receipts.rollup_title') }} · {{ $asn->asn_no }} @if ($asn->receiving_completed_at)<span class="badge" data-tone="ok">{{ __('warehouse.asns.receiving_completed_badge') }}</span>@endif</h2>
    @include('warehouse::receipts._batches', ['asn' => $asn, 'batches' => $batches, 'rollup' => $rollup])
@endsection
