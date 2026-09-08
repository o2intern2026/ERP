@extends('layouts.app')

@section('title', __('billing.quotes.title'))

@section('content')
    <header class="grid"><h1>{{ __('billing.quotes.title') }}</h1><p style="text-align:right"><a role="button" href="{{ route('billing.quotes.create') }}">{{ __('billing.quotes.create') }}</a></p></header>
    @if ($quotes->isEmpty())
        <p class="text-muted">{{ __('billing.quotes.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('billing.quotes.no') }}</th><th>{{ __('billing.quotes.client') }}</th><th>{{ __('billing.quotes.stage') }}</th><th>{{ __('billing.quotes.valid_until') }}</th><th class="num">{{ __('billing.quotes.total') }}</th><th>{{ __('billing.quotes.status') }}</th></tr></thead>
            <tbody>@foreach ($quotes as $q)<tr><td><a href="{{ route('billing.quotes.show', $q) }}">{{ $q->quote_no }}</a></td><td>{{ $q->client->name }}</td><td>{{ __('billing.quotes.stages.'.$q->stage) }}</td><td>{{ $q->valid_until?->format('Y-m-d') }}</td><td class="num">{{ \App\Support\Money::cents((int) round($q->total_cents))->format() }}</td><td><span class="badge" data-tone="{{ ['draft' => 'muted', 'sent' => 'warn', 'accepted' => 'ok', 'rejected' => 'danger', 'expired' => 'muted'][$q->status] }}">{{ __('billing.quotes.statuses.'.$q->status) }}</span></td></tr>@endforeach</tbody>
        </table>
        {{ $quotes->links() }}
    @endif
@endsection
