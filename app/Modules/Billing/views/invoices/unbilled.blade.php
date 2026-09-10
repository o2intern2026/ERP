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
                    <span class="num">{{ \App\Support\Money::cents((int) round($entry['amount_cents']))->format() }} {{ __('billing.money') }}
                        @if ($entry['storage_count'] > 0)<br><small class="text-muted">{{ __('billing.unbilled.service_part') }} {{ \App\Support\Money::cents($entry['service_amount_cents'])->format() }} · {{ __('billing.unbilled.storage_part') }} {{ \App\Support\Money::cents($entry['storage_amount_cents'])->format() }}</small>@endif
                    </span>
                </header>
                <table class="dense">
                    <tbody>
                    @foreach ($entry['jobs'] as $row)
                        <tr>
                            <td><a href="{{ route('platform.jobs.show', $row['job']) }}">{{ $row['job']->job_no }}</a> <small class="text-muted">{{ $row['job']->reference }}</small></td>
                            <td class="num">{{ $row['count'] }} {{ __('billing.unbilled.lines') }}</td>
                            <td class="num">{{ \App\Support\Money::cents((int) round($row['service_amount_cents']))->format() }}
                                @if ($row['storage_count'] > 0)<br><small class="text-muted">{{ __('billing.unbilled.storage_note', ['amount' => \App\Support\Money::cents($row['storage_amount_cents'])->format()]) }}</small>@endif
                            </td>
                            <td>
                                @if ($row['service_count'] > 0)
                                    <form method="post" action="{{ route('billing.invoices.draft_job', $row['job']) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('billing.unbilled.draft_job') }}</button></form>
                                @else
                                    <small class="text-muted">{{ __('billing.unbilled.storage_only') }}</small>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <footer>
                    <form method="post" action="{{ route('billing.invoices.draft_period') }}" class="erp-period-form" data-client="{{ $entry['client']->id }}">
                        @csrf<input type="hidden" name="client_id" value="{{ $entry['client']->id }}">
                        <p class="text-muted"><small>{{ __('billing.unbilled_period.period_hint', ['period' => __('masterdata.invoice_periods.'.($entry['client']->invoice_period ?? 'monthly')), 'grouping' => __('masterdata.invoice_groupings.'.($entry['client']->invoice_grouping ?? 'job'))]) }}</small></p>
                        <div class="grid">
                            <label>{{ __('billing.unbilled_period.period_from') }}<input type="date" name="from" value="{{ now()->startOfMonth()->toDateString() }}" required></label>
                            <label>{{ __('billing.unbilled_period.period_to') }}<input type="date" name="to" value="{{ now()->endOfMonth()->toDateString() }}" required></label>
                            <label>{{ __('billing.unbilled_period.scope') }}<select name="scope">@foreach (\App\Support\Enums::INVOICE_SCOPES as $scope)<option value="{{ $scope }}">{{ __('billing.unbilled_period.scopes.'.$scope) }}</option>@endforeach</select></label>
                            <label>{{ __('billing.unbilled_period.group_by') }}<select name="group_by">@foreach (\App\Support\Enums::INVOICE_GROUPINGS as $g)<option value="{{ $g }}" @selected(($entry['client']->invoice_grouping ?? 'job') === $g)>{{ __('billing.invoices.group_by.'.$g) }}</option>@endforeach</select></label>
                        </div>
                        <div class="grid">
                            <div>
                                @foreach (['this_week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()], 'last_week' => [now()->subWeek()->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString()], 'last_fortnight' => [now()->subWeeks(2)->startOfWeek()->toDateString(), now()->subWeek()->endOfWeek()->toDateString()], 'this_month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()], 'last_month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()]] as $preset => [$f, $t])
                                    <button type="button" class="outline secondary erp-preset" data-from="{{ $f }}" data-to="{{ $t }}" style="padding:.2rem .6rem;margin:0 .25rem .25rem 0">{{ __('billing.unbilled_period.presets.'.$preset) }}</button>
                                @endforeach
                            </div>
                            <button type="submit" class="secondary">{{ __('billing.unbilled_period.draft_period') }}</button>
                        </div>
                    </form>
                    <form method="post" action="{{ route('billing.invoices.draft_monthly') }}" class="grid" hidden>
                        @csrf<input type="hidden" name="client_id" value="{{ $entry['client']->id }}">
                        <input type="date" name="from" value="{{ now()->startOfMonth()->toDateString() }}" aria-label="{{ __('billing.unbilled_period.period_from') }}"><input type="date" name="to" value="{{ now()->endOfMonth()->toDateString() }}" aria-label="{{ __('billing.unbilled_period.period_to') }}">
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
    <script>
        document.querySelectorAll('.erp-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = btn.closest('form');
                form.querySelector('[name=from]').value = btn.dataset.from;
                form.querySelector('[name=to]').value = btn.dataset.to;
            });
        });
    </script>
@endsection
