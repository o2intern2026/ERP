@extends('layouts.app')

@section('title', __('billing.receivables.title'))

@section('content')
    <h1>{{ __('billing.receivables.title') }}</h1>
    <p class="text-muted"><small>{{ __('billing.receivables.hint') }}</small></p>
    @if ($byClient->isEmpty())
        <p class="text-muted">{{ __('billing.receivables.empty') }}</p>
    @else
        <table class="dense">
            <thead><tr><th>{{ __('billing.receivables.client') }}</th><th class="num">{{ __('billing.receivables.open_invoices') }}</th><th class="num">{{ __('billing.receivables.outstanding') }}</th><th class="num">{{ __('billing.receivables.overdue') }}</th></tr></thead>
            <tbody>@foreach ($byClient as $row)<tr><td><a href="{{ route('billing.invoices.index', ['client_id' => $row['client']->id]) }}">{{ $row['client']->name }}</a></td><td class="num">{{ $row['count'] }}</td><td class="num">{{ number_format($row['outstanding_cents'] / 100, 2) }}</td><td class="num">@if ($row['overdue_cents'])<span class="badge" data-tone="danger">{{ number_format($row['overdue_cents'] / 100, 2) }}</span>@else — @endif</td></tr>@endforeach</tbody>
        </table>
    @endif
@endsection
