@extends('layouts.app')

@section('title', __('billing.rate_cards.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('billing.rate_cards.title') }}</h1>
        <p style="text-align:right"><a href="{{ route('billing.charge_codes.index') }}" role="button" class="secondary outline">{{ __('billing.charge_codes.title') }}</a></p>
    </header>
    <details>
        <summary role="button" class="secondary outline">{{ __('billing.rate_cards.create') }}</summary>
        <form method="post" action="{{ route('billing.rate_cards.store') }}" class="grid">
            @csrf
            <select name="client_id" required><option value="">{{ __('billing.rate_cards.client') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
            <input type="text" name="name" placeholder="{{ __('billing.rate_cards.name') }}" required>
            <input type="date" name="effective_from" value="{{ today()->toDateString() }}" required>
            <button type="submit" class="secondary">{{ __('billing.rate_cards.create') }}</button>
        </form>
    </details>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('billing.rate_cards.name') }}</th><th>{{ __('billing.rate_cards.client') }}</th><th class="num">{{ __('billing.rate_cards.version') }}</th><th>{{ __('billing.rate_cards.effective_from') }}</th><th>{{ __('billing.rate_cards.effective_to') }}</th><th class="num">{{ __('billing.rate_cards.items') }}</th><th>{{ __('billing.rate_cards.status') }}</th></tr></thead>
        <tbody>
        @foreach ($cards as $card)
            <tr><td><a href="{{ route('billing.rate_cards.show', $card) }}">{{ $card->name }}</a></td><td>{{ $card->is_standard ? __('billing.rate_cards.standard') : $card->client?->name }}</td><td class="num">v{{ $card->version }}</td><td>{{ $card->effective_from->format('Y-m-d') }}</td><td>{{ $card->effective_to?->format('Y-m-d') ?? '—' }}</td><td class="num">{{ $card->items_count }}</td><td><span class="badge" data-tone="{{ ['draft' => 'warn', 'active' => 'ok', 'superseded' => 'muted'][$card->status] }}">{{ __('billing.rate_cards.statuses.'.$card->status) }}</span></td></tr>
        @endforeach
        </tbody>
    </table></div>
@endsection
