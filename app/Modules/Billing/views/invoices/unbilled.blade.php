@extends('layouts.app')

@section('title', __('billing.unbilled.title'))

@section('content')
    <h1>{{ __('billing.unbilled.title') }}</h1>
    <p class="text-muted"><small>{{ __('billing.unbilled.hint') }} @if ($reviewCount) · <a href="{{ route('billing.charges.review') }}">{{ __('billing.unbilled.review_pending', ['n' => $reviewCount]) }}</a>@endif @if ($missingRateCount) · <a href="{{ route('platform.exceptions.index', ['type' => 'missing_rate']) }}">{{ __('billing.unbilled.missing_rate_total', ['n' => $missingRateCount]) }}</a>@endif</small></p>
    {{-- Audit 2026-09-22 FIN-03 (CR #132): clients with unpriced revenue only (no pooled charge) — otherwise invisible until someone opens the exception centre. --}}
    @if ($attentionClients->isNotEmpty())
        <article>
            <header><strong>{{ __('billing.unbilled.attention_title') }}</strong> <small class="text-muted">{{ __('billing.unbilled.attention_hint') }}</small></header>
            @foreach ($attentionClients as $ac)
                @php($missingN = (int) ($missingByClient[$ac->id] ?? 0))
                @php($reviewN = (int) ($reviewByClient[$ac->id] ?? 0))
                <p>{{ $ac->name }} ·
                    @if ($missingN)<a href="{{ route('platform.exceptions.index', ['type' => 'missing_rate', 'client_id' => $ac->id]) }}"><span class="badge" data-tone="danger">{{ __('billing.unbilled.missing_rate_count', ['n' => $missingN]) }}</span></a>@endif
                    @if ($reviewN)<a href="{{ route('billing.charges.review', ['client_id' => $ac->id]) }}"><span class="badge" data-tone="warn">{{ __('billing.unbilled.review_count', ['n' => $reviewN]) }}</span></a>@endif
                </p>
            @endforeach
        </article>
    @endif
    @if ($pool->isEmpty())
        <p class="text-muted">{{ __('billing.unbilled.empty') }}</p>
    @else
        @foreach ($pool as $entry)
            <article>
                <header class="grid">
                    <strong>{{ $entry['client']->name }} <small class="text-muted">· {{ __('masterdata.invoice_modes.'.$entry['client']->invoice_mode) }} · {{ $entry['client']->payment_terms }}</small>
                        {{-- Audit 2026-09-22 FIN-03 (CR #132): what this client has waiting OUTSIDE the pool — $0 missing-rate / POA rows and open missing-rate exceptions. --}}
                        @php($missingN = (int) ($missingByClient[$entry['client']->id] ?? 0))
                        @php($reviewN = (int) ($reviewByClient[$entry['client']->id] ?? 0))
                        @if ($missingN || $reviewN)
                            <br><small>
                                @if ($missingN)<a href="{{ route('platform.exceptions.index', ['type' => 'missing_rate', 'client_id' => $entry['client']->id]) }}"><span class="badge" data-tone="danger">{{ __('billing.unbilled.missing_rate_count', ['n' => $missingN]) }}</span></a>@endif
                                @if ($missingN && $reviewN) · @endif
                                @if ($reviewN)<a href="{{ route('billing.charges.review', ['client_id' => $entry['client']->id]) }}"><span class="badge" data-tone="warn">{{ __('billing.unbilled.review_count', ['n' => $reviewN]) }}</span></a>@endif
                                <span class="text-muted">{{ __('billing.unbilled.attention_hint') }}</span>
                            </small>
                        @endif
                    </strong>
                    <span class="num">{{ \App\Support\Money::cents((int) round($entry['amount_cents']))->format() }} {{ __('billing.money') }}
                        @if ($entry['storage_count'] > 0)<br><small class="text-muted">{{ __('billing.unbilled.service_part') }} {{ \App\Support\Money::cents($entry['service_amount_cents'])->format() }} · {{ __('billing.unbilled.storage_part') }} {{ \App\Support\Money::cents($entry['storage_amount_cents'])->format() }}</small>@endif
                    </span>
                </header>
                <table class="dense">
                    <tbody>
                    @foreach ($entry['jobs'] as $row)
                        <tr>
                            <td><a href="{{ route('platform.jobs.show', $row['job']) }}">{{ $row['job']->job_no }}</a> <small class="text-muted">{{ $row['job']->reference }}</small></td>
                            {{-- Both figures describe the service charges 按此 Job 开票 will draft; storage lines are counted in the note below. --}}
                            <td class="num">{{ $row['service_count'] }} {{ __('billing.unbilled.lines') }}</td>
                            <td class="num">{{ \App\Support\Money::cents((int) round($row['service_amount_cents']))->format() }}
                                @if ($row['storage_count'] > 0)<br><small class="text-muted">{{ __('billing.unbilled.storage_note', ['count' => $row['storage_count'], 'amount' => \App\Support\Money::cents($row['storage_amount_cents'])->format()]) }}</small>@endif
                            </td>
                            <td>
                                @if ($row['service_count'] > 0)
                                    {{-- Audit 2026-09-22 FIN-10 (CR #140): a Job that already sits on an open draft is not drafted a second time — its new charges join that draft. --}}
                                    @if ($draft = $draftsByJob[$row['job']->id] ?? null)
                                        <a href="{{ route('billing.invoices.show', $draft) }}">{{ __('billing.unbilled.has_draft', ['no' => $draft->invoice_no, 'amount' => \App\Support\Money::cents((int) $draft->total_cents)->format()]) }}</a>
                                        <form method="post" action="{{ route('billing.invoices.append_job', [$draft, $row['job']]) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('billing.unbilled.append_to_draft') }}</button></form>
                                        <br><small class="text-muted">{{ __('billing.unbilled.has_draft_hint') }}</small>
                                    @else
                                        <form method="post" action="{{ route('billing.invoices.draft_job', $row['job']) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('billing.unbilled.draft_job') }}</button></form>
                                    @endif
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
                            {{-- Audit 2026-09-10: defaults follow the pool (its date span and whether it holds storage / service / both) so the header figure and the button agree. --}}
                            <label>{{ __('billing.unbilled_period.period_from') }}<x-date-field name="from" value="{{ $entry['period_from'] ?? now()->startOfMonth()->toDateString() }}" required /></label>
                            <label>{{ __('billing.unbilled_period.period_to') }}<x-date-field name="to" value="{{ $entry['period_to'] ?? now()->endOfMonth()->toDateString() }}" required /></label>
                            <label>{{ __('billing.unbilled_period.scope') }}<select name="scope">@foreach (\App\Support\Enums::INVOICE_SCOPES as $scope)<option value="{{ $scope }}" @selected(($entry['default_scope'] ?? 'service') === $scope)>{{ __('billing.unbilled_period.scopes.'.$scope) }}</option>@endforeach</select></label>
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
                        <x-date-field name="from" value="{{ now()->startOfMonth()->toDateString() }}" aria-label="{{ __('billing.unbilled_period.period_from') }}" /><x-date-field name="to" value="{{ now()->endOfMonth()->toDateString() }}" aria-label="{{ __('billing.unbilled_period.period_to') }}" />
                        <button type="submit" class="secondary">{{ __('billing.unbilled.draft_monthly') }}</button>
                    </form>
                    @if ($entry['has_storage'])
                        <form method="post" action="{{ route('billing.invoices.draft_storage') }}" class="grid">
                            @csrf<input type="hidden" name="client_id" value="{{ $entry['client']->id }}">
                            <x-date-field name="week" value="{{ now()->subWeek()->toDateString() }}" aria-label="{{ __('billing.unbilled.week') }}" />
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
                // from / to are x-date-field hidden inputs: set the ISO value and dispatch change so the dd/mm/yyyy text follows.
                ['from', 'to'].forEach(function (name) { var input = form.querySelector('[name=' + name + ']'); input.value = btn.dataset[name]; input.dispatchEvent(new Event('change', { bubbles: true })); });
            });
        });
    </script>
@endsection
