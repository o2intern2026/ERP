@extends('layouts.app')

@section('title', __('billing.charges.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('billing.title') }} · {{ __('billing.charges.title') }}</h1>
        <p style="text-align:right"><a role="button" class="secondary" href="{{ route('billing.charges.review') }}">{{ __('billing.charges.review_queue') }} ({{ $reviewCount }})</a> <a role="button" class="secondary outline" href="{{ route('billing.charges.manual') }}">{{ __('billing.charges.manual_title') }}</a></p>
    </header>
    {{-- Audit 2026-09-22 FIN-10 (CR #140): 待开票 = the unbilled pool's rows exactly; rows already on a draft are counted apart (they keep status pending / approved). --}}
    <p class="text-muted"><small>{{ __('billing.charges.counts', ['pending' => $poolCount, 'drafted' => $draftedCount, 'needs_review' => $counts['needs_review']->n ?? 0, 'invoiced' => $counts['invoiced']->n ?? 0, 'reversed' => $counts['reversed']->n ?? 0]) }} · <a href="{{ route('billing.unbilled') }}">{{ __('billing.nav_unbilled') }}</a> · <a href="{{ route('billing.invoices.index', ['status' => 'draft']) }}">{{ __('billing.charges.drafts_link') }}</a></small></p>
    <form method="get" class="grid">
        <select name="client_id"><option value="">{{ __('billing.charges.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <input type="text" name="job_no" placeholder="{{ __('billing.charges.job') }}" value="{{ $filters['job_no'] ?? '' }}">
        <input type="text" name="code" placeholder="{{ __('billing.charges.code') }}" value="{{ $filters['code'] ?? '' }}">
        <select name="status"><option value="">{{ __('billing.charges.status') }}: {{ __('platform.jobs.all') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('billing.charge_statuses.'.$s) }}</option>@endforeach</select>
        <x-date-field name="from" value="{{ $filters['from'] ?? '' }}" /><x-date-field name="to" value="{{ $filters['to'] ?? '' }}" />
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($charges->isEmpty())
        <p class="text-muted">{{ __('billing.charges.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('billing.charges.date') }}</th><th>{{ __('billing.charges.client') }}</th><th>{{ __('billing.charges.job') }}</th><th>{{ __('billing.charges.code') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th>{{ __('billing.charges.uom') }}</th><th class="num">{{ __('billing.charges.rate') }}</th><th class="num">{{ __('billing.charges.amount') }}</th><th>{{ __('billing.charges.status') }}</th><th>{{ __('billing.charges.invoice') }}</th><th>{{ __('billing.charges.source') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($charges as $c)
                @php($invoice = $c->invoiceLine?->invoice)
                <tr>
                    <td>{{ $c->id }}@if ($c->reversal_of_charge_id)<br><small class="text-muted">↩ #{{ $c->reversal_of_charge_id }}</small>@endif</td>
                    <td>{{ $c->charge_date->format('Y-m-d') }}</td><td>{{ $c->client->name }}</td><td><a href="{{ route('platform.jobs.show', $c->job) }}">{{ $c->job->job_no }}</a></td>
                    <td><code>{{ $c->chargeCode->code }}</code><br><small class="text-muted">{{ $c->chargeCode->customer_description }}@if ($c->is_manual) · {{ __('billing.charges.manual') }}: {{ $c->manual_reason }}@endif</small></td>
                    <td class="num">{{ rtrim(rtrim(number_format($c->qty, 3), '0'), '.') }}</td><td>{{ $c->uom }}</td>
                    <td class="num">{{ $c->rate_snapshot_cents !== null ? \App\Support\Money::cents((int) round($c->rate_snapshot_cents))->format() : __('billing.rate_cards.poa') }}</td>
                    <td class="num">{{ \App\Support\Money::cents((int) round($c->amount_cents))->format() }}</td>
                    <td><span class="badge" data-tone="{{ ['pending' => 'warn', 'needs_review' => 'danger', 'approved' => 'ok', 'invoiced' => 'ok', 'disputed' => 'danger', 'reversed' => 'muted'][$c->status] }}">{{ __('billing.charge_statuses.'.$c->status) }}</span></td>
                    {{-- FIN-10: a charge can be traced forward — the draft it sits on (草稿中) or the issued invoice number. --}}
                    <td>@if ($invoice)<a href="{{ route('billing.invoices.show', $invoice) }}">{{ $invoice->invoice_no }}</a>@if ($invoice->status === 'draft') <span class="badge" data-tone="muted">{{ __('billing.charges.in_draft') }}</span>@endif @else — @endif</td>
                    <td><small>{{ \Illuminate\Support\Facades\Lang::has('platform.source_types.'.$c->source_type) ? __('platform.source_types.'.$c->source_type) : $c->source_type }} #{{ $c->source_id }}<br>{{ $c->source_activity_id }} v{{ $c->activity_version }}</small></td>
                    <td>
                        {{-- Audit 2026-09-22 FIN-16 (CR #140): an invoiced charge is reduced by a credit note on its invoice (a 冲销 here would refund it a second time);
                             a charge on a draft is handled on the draft; only a pooled charge is reversed here. --}}
                        @if ($c->status === 'invoiced' && $invoice)
                            <a href="{{ route('billing.invoices.show', $invoice) }}?credit_line={{ $c->invoice_line_id }}#credit-note" title="{{ __('billing.charges.to_credit_note_hint') }}">{{ __('billing.charges.to_credit_note') }}</a>
                        @elseif ($invoice && $invoice->status === 'draft' && in_array($c->status, ['pending', 'approved'], true))
                            <small class="text-muted">{{ __('billing.charges.on_draft_hint') }}</small>
                        @elseif (in_array($c->status, ['pending', 'approved'], true) && ! $c->reversal_of_charge_id)
                            <form method="post" action="{{ route('billing.charges.reverse', $c) }}" class="inline">@csrf<input type="text" name="reason" placeholder="{{ __('billing.charges.reverse_reason') }}" style="width:10rem" required><button type="submit" class="secondary outline">{{ __('billing.charges.reverse') }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $charges->links() }}
    @endif
@endsection
