@extends('layouts.app')

@section('title', $invoice->invoice_no)

@section('content')
    <p><a href="{{ route('billing.invoices.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $invoice->invoice_no }} <span class="badge" data-tone="{{ ['draft' => 'muted', 'issued' => 'warn', 'part_paid' => 'warn', 'paid' => 'ok', 'void' => 'muted'][$invoice->status] }}">{{ __('billing.invoices.statuses.'.$invoice->status) }}</span> @if ($invoice->is_overdue)<span class="badge" data-tone="danger">{{ __('billing.invoices.overdue') }}</span>@endif</h1>
        <p>{{ __('billing.invoices.types.'.$invoice->invoice_type) }} · {{ $invoice->client->name }} · {{ __('billing.invoices.jobs') }}: {{ $invoice->jobs->pluck('job_no')->implode(', ') }} @if ($invoice->period_from)· {{ __('billing.invoices.period') }} {{ $invoice->period_from->format('Y-m-d') }} → {{ $invoice->period_to?->format('Y-m-d') }}@endif</p>
    </header>
    <div class="grid">
        <article><header>{{ __('billing.invoices.bill_to') }}</header>{{ $invoice->bill_to_name }}<br>{{ $invoice->bill_to_address }}<br>@if ($invoice->bill_to_abn) ABN {{ $invoice->bill_to_abn }} @endif</article>
        <article><header>{{ __('billing.invoices.total') }}</header>
            {{ __('billing.invoices.subtotal') }}: {{ \App\Support\Money::cents((int) round($invoice->subtotal_cents))->format() }}<br>{{ __('billing.invoices.gst') }}: {{ \App\Support\Money::cents((int) round($invoice->gst_cents))->format() }}<br><strong>{{ __('billing.invoices.total') }}: {{ \App\Support\Money::cents((int) round($invoice->total_cents))->format() }} {{ __('billing.money') }}</strong><br>
            {{ __('billing.invoices.paid') }}: {{ \App\Support\Money::cents((int) round($invoice->paid_amount_cents))->format() }} · {{ __('billing.invoices.outstanding') }}: <strong>{{ \App\Support\Money::cents((int) round($invoice->outstandingCents()))->format() }}</strong><br>
            {{ __('billing.invoices.issued_at') }}: {{ $invoice->issued_at?->format('Y-m-d') ?? '—' }} · {{ __('billing.invoices.due_at') }}: {{ $invoice->due_at?->format('Y-m-d') ?? '—' }}
        </article>
        <article>
            @if ($invoice->status === 'draft')
                <p class="text-muted"><small>{{ __('billing.invoices.draft_hint') }}</small></p>
                <form method="post" action="{{ route('billing.invoices.issue', $invoice) }}">@csrf<button type="submit">{{ __('billing.invoices.issue') }}</button></form>
                <form method="post" action="{{ route('billing.invoices.destroy', $invoice) }}">@csrf @method('DELETE')<button type="submit" class="secondary outline">{{ __('billing.invoices.discard') }}</button></form>
            @else
                <p><a role="button" class="secondary" href="{{ route('billing.invoices.pdf', $invoice) }}" target="_blank">{{ __('billing.invoices.pdf') }}</a></p>
                @if (in_array($invoice->status, ['issued', 'part_paid']))
                    <form method="post" action="{{ route('billing.invoices.payments.store', $invoice) }}">
                        @csrf
                        <div class="grid"><input type="number" step="0.01" min="0.01" name="amount" placeholder="{{ __('billing.invoices.payment_amount') }}" required><input type="date" name="paid_at" value="{{ today()->toDateString() }}" required></div>
                        <div class="grid"><select name="method">@foreach (['bank', 'card', 'cash', 'other'] as $m)<option value="{{ $m }}">{{ __('billing.invoices.methods.'.$m) }}</option>@endforeach</select><input type="text" name="reference" placeholder="{{ __('billing.invoices.reference') }}"></div>
                        <button type="submit" class="secondary">{{ __('billing.invoices.record_payment') }}</button>
                    </form>
                @endif
            @endif
        </article>
    </div>

    <h2>{{ __('billing.invoices.lines') }}</h2>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('billing.charges.code') }}</th><th>{{ __('billing.charges.description') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th>{{ __('billing.charges.uom') }}</th><th class="num">{{ __('billing.charges.amount') }}</th><th class="num">{{ __('billing.invoices.gst') }}</th><th>{{ __('billing.charges.source') }}</th></tr></thead>
        <tbody>
        @foreach ($groups as $group)
            @php($lines = $group['lines'])
            <tr><td colspan="7"><strong>{{ $group['title'] }}</strong> <small class="text-muted">{{ __('billing.invoices.group_by.'.$invoice->group_by) }}</small></td></tr>
            @foreach ($lines as $l)
                <tr><td><code>{{ $l->charge_code }}</code></td><td>{{ $l->description }}</td><td class="num">{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }}</td><td>{{ $l->uom }}</td><td class="num">{{ \App\Support\Money::cents((int) round($l->amount_cents))->format() }}</td><td class="num">{{ \App\Support\Money::cents((int) round($l->gst_cents))->format() }}</td><td><small>@if ($l->charge)<a href="{{ route('billing.index', ['job_no' => $l->job?->job_no]) }}">#{{ $l->charge_id }}</a> · {{ $l->charge->source_type }} #{{ $l->charge->source_id }}@endif</small></td></tr>
            @endforeach
        @endforeach
        </tbody>
    </table></div>

    @if ($invoice->status !== 'draft')
        <div class="grid">
            <article>
                <header>{{ __('billing.invoices.payments') }}</header>
                @forelse ($invoice->payments as $p)<p>{{ $p->paid_at->format('Y-m-d') }} · {{ __('billing.invoices.methods.'.$p->method) }} · {{ $p->reference }} · <strong>{{ \App\Support\Money::cents((int) round($p->amount_cents))->format() }}</strong></p>@empty<p class="text-muted">—</p>@endforelse
            </article>
            <article>
                <header>{{ __('billing.credit_notes.title') }}</header>
                @foreach ($invoice->creditNotes as $n)
                    <p>{{ $n->credit_note_no }} · {{ $n->reason }} · {{ \App\Support\Money::cents((int) round(($n->amount_cents + $n->gst_cents)))->format() }} · <span class="badge" data-tone="{{ $n->status === 'issued' ? 'ok' : 'warn' }}">{{ __('billing.credit_notes.statuses.'.$n->status) }}</span>
                        @if ($n->status !== 'issued')<form method="post" action="{{ route('billing.credit_notes.issue', $n) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('billing.credit_notes.issue') }}</button></form>@endif</p>
                @endforeach
                <details>
                    <summary>{{ __('billing.credit_notes.new') }}</summary>
                    <form method="post" action="{{ route('billing.invoices.credit_notes.store', $invoice) }}">
                        @csrf
                        <input type="text" name="reason" placeholder="{{ __('billing.credit_notes.reason') }}" required>
                        @foreach ($invoice->lines as $i => $l)
                            <div class="grid"><span>{{ $l->charge_code }} · {{ $l->description }} ({{ \App\Support\Money::cents((int) round($l->amount_cents))->format() }})</span><input type="hidden" name="lines[{{ $i }}][invoice_line_id]" value="{{ $l->id }}"><input type="number" step="0.01" min="0" max="{{ $l->amount_cents / 100 }}" name="lines[{{ $i }}][amount]" placeholder="{{ __('billing.credit_notes.line_amount') }}"></div>
                        @endforeach
                        <button type="submit" class="secondary">{{ __('billing.credit_notes.new') }}</button>
                    </form>
                </details>
            </article>
        </div>
    @endif
@endsection
