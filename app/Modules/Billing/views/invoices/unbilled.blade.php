@extends('layouts.app')

@section('title', __('billing.unbilled.title'))

@section('content')
    <h1>{{ __('billing.unbilled.title') }}</h1>
    <p class="text-muted"><small>{{ __('billing.unbilled.hint') }} @if ($reviewCount) · <a href="{{ route('billing.charges.review') }}">{{ __('billing.unbilled.review_pending', ['n' => $reviewCount]) }}</a>@endif</small></p>
    @if ($pool->isEmpty())
        <p class="text-muted">{{ __('billing.unbilled.empty') }}</p>
    @else
        @foreach ($pool as $entry)
            <article>
                <header class="grid">
                    <strong>{{ $entry['client']->name }} <small class="text-muted">· {{ __('masterdata.invoice_modes.'.$entry['client']->invoice_mode) }} · {{ $entry['client']->payment_terms }}</small></strong>
                    <span class="num">{{ number_format($entry['amount_cents'] / 100, 2) }} {{ __('billing.money') }}</span>
                </header>
                <table class="dense">
                    <tbody>
                    @foreach ($entry['jobs'] as $row)
                        <tr>
                            <td><a href="{{ route('platform.jobs.show', $row['job']) }}">{{ $row['job']->job_no }}</a> <small class="text-muted">{{ $row['job']->reference }}</small></td>
                            <td class="num">{{ $row['count'] }} {{ __('billing.unbilled.lines') }}</td>
                            <td class="num">{{ number_format($row['amount_cents'] / 100, 2) }}</td>
                            <td><form method="post" action="{{ route('billing.invoices.draft_job', $row['job']) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('billing.unbilled.draft_job') }}</button></form></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <footer class="grid">
                    <form method="post" action="{{ route('billing.invoices.draft_monthly') }}" class="grid">
                        @csrf<input type="hidden" name="client_id" value="{{ $entry['client']->id }}">
                        <input type="date" name="from" value="{{ now()->startOfMonth()->toDateString() }}" aria-label="{{ __('billing.unbilled.period_from') }}"><input type="date" name="to" value="{{ now()->endOfMonth()->toDateString() }}" aria-label="{{ __('billing.unbilled.period_to') }}">
                        <button type="submit" class="secondary">{{ __('billing.unbilled.draft_monthly') }}</button>
                    </form>
                    @if ($entry['has_storage'])
                        <form method="post" action="{{ route('billing.invoices.draft_storage') }}" class="grid">
                            @csrf<input type="hidden" name="client_id" value="{{ $entry['client']->id }}">
                            <input type="date" name="week" value="{{ now()->subWeek()->toDateString() }}" aria-label="{{ __('billing.unbilled.week') }}">
                            <button type="submit" class="secondary">{{ __('billing.unbilled.draft_storage') }}</button>
                        </form>
                    @endif
                </footer>
            </article>
        @endforeach
    @endif
@endsection
