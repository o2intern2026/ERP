@extends('layouts.app')

@section('title', __('warehouse.receipts.title'))

@section('content')
    <h1>{{ __('warehouse.receipts.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.receipts.hint') }}</small></p>
    <form method="get" class="grid">
        <select name="client_id"><option value="">{{ __('warehouse.receipts.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <select name="status"><option value="">{{ __('warehouse.receipts.status') }}: {{ __('platform.jobs.all') }}</option>@foreach (['open', 'completed'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('warehouse.receipt_statuses.'.$s) }}</option>@endforeach</select>
        <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" aria-label="{{ __('warehouse.receipts.date_from') }}">
        <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" aria-label="{{ __('warehouse.receipts.date_to') }}">
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($receipts->isEmpty())
        <p class="text-muted">{{ __('warehouse.receipts.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.receipts.receipt_no') }}</th><th>{{ __('warehouse.receipts.asn_no') }}</th><th>{{ __('warehouse.receipts.client') }}</th><th>{{ __('warehouse.receipts.warehouse') }}</th><th class="num">{{ __('warehouse.receipts.batch') }}</th><th class="num">{{ __('warehouse.receipts.lines') }}</th><th class="num">{{ __('warehouse.receipts.expected') }}</th><th class="num">{{ __('warehouse.receipts.received') }}</th><th class="num">{{ __('warehouse.receipts.damaged') }}</th><th>{{ __('warehouse.receipts.status') }}</th><th>{{ __('warehouse.receipts.completed_at') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($receipts as $r)
                <tr>
                    <td><a href="{{ route('warehouse.receipts.show', $r) }}">{{ $r->receipt_no }}</a> @if ($r->unplanned)<span class="badge" data-tone="warn">{{ __('warehouse.asns.unplanned_badge') }}</span>@endif</td>
                    <td><a href="{{ route('warehouse.asns.show', $r->asn_id) }}">{{ $r->asn->asn_no }}</a></td>
                    <td>{{ $r->client->name }}</td><td>{{ $r->warehouse->code }}</td>
                    <td class="num">{{ $r->batch_no }}</td><td class="num">{{ $r->lines_count }}</td>
                    <td class="num">{{ $r->isOpen() ? '—' : $r->expected_cartons }}</td><td class="num">{{ $r->isOpen() ? '—' : $r->received_cartons }}</td><td class="num">{{ $r->isOpen() ? '—' : $r->damaged_cartons }}</td>
                    <td><span class="badge" data-tone="{{ $r->isOpen() ? 'warn' : 'ok' }}">{{ __('warehouse.receipt_statuses.'.$r->status) }}</span></td>
                    <td>{{ $r->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td><a href="{{ route('warehouse.receipts.show', $r) }}">{{ __('warehouse.receipts.view') }}</a> · <a href="{{ route('warehouse.receipts.pdf', $r) }}" target="_blank">PDF</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $receipts->links() }}
    @endif
@endsection
