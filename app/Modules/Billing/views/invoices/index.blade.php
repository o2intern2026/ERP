@extends('layouts.app')

@section('title', __('billing.invoices.title'))

@section('content')
    <h1>{{ __('billing.invoices.title') }}</h1>
    <form method="get" class="grid">
        <select name="status"><option value="">{{ __('billing.invoices.status') }}: {{ __('platform.jobs.all') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('billing.invoices.statuses.'.$s) }}</option>@endforeach</select>
        <select name="client_id"><option value="">{{ __('billing.invoices.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($invoices->isEmpty())
        <p class="text-muted">{{ __('billing.invoices.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('billing.invoices.no') }}</th><th>{{ __('billing.invoices.type') }}</th><th>{{ __('billing.invoices.client') }}</th><th>{{ __('billing.invoices.issued_at') }}</th><th>{{ __('billing.invoices.due_at') }}</th><th class="num">{{ __('billing.invoices.total') }}</th><th class="num">{{ __('billing.invoices.paid') }}</th><th>{{ __('billing.invoices.status') }}</th></tr></thead>
            <tbody>
            @foreach ($invoices as $i)
                <tr>
                    <td><a href="{{ route('billing.invoices.show', $i) }}">{{ $i->invoice_no }}</a></td><td>{{ __('billing.invoices.types.'.$i->invoice_type) }}</td><td>{{ $i->client->name }}</td>
                    <td>{{ $i->issued_at?->format('Y-m-d') ?? '—' }}</td><td>{{ $i->due_at?->format('Y-m-d') ?? '—' }} @if ($i->is_overdue)<span class="badge" data-tone="danger">{{ __('billing.invoices.overdue') }}</span>@endif</td>
                    <td class="num">{{ \App\Support\Money::cents((int) round($i->total_cents))->format() }}</td><td class="num">{{ \App\Support\Money::cents((int) round($i->paid_amount_cents))->format() }}</td>
                    <td><span class="badge" data-tone="{{ ['draft' => 'muted', 'issued' => 'warn', 'part_paid' => 'warn', 'paid' => 'ok', 'void' => 'muted'][$i->status] }}">{{ __('billing.invoices.statuses.'.$i->status) }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $invoices->links() }}
    @endif
@endsection
