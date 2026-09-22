@extends('layouts.app')

@section('title', $invoice->invoice_no)

@section('content')
    <p><a href="{{ route('billing.invoices.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $invoice->invoice_no }} <span class="badge" data-tone="{{ ['draft' => 'muted', 'issued' => 'warn', 'part_paid' => 'warn', 'paid' => 'ok', 'void' => 'muted'][$invoice->status] }}">{{ __('billing.invoices.statuses.'.$invoice->status) }}</span> @if ($invoice->is_overdue)<span class="badge" data-tone="danger">{{ __('billing.invoices.overdue') }}</span>@endif @if ($issuedWithUnpricedOverride)<span class="badge" data-tone="warn" title="{{ $invoice->notes }}">{{ __('billing.invoices.unpriced_override_badge') }}</span>@endif</h1>
        <p>{{ __('billing.invoices.types.'.$invoice->invoice_type) }} · {{ $invoice->client->name }} · {{ __('billing.invoices.jobs') }}: {{ $invoice->jobs->pluck('job_no')->implode(', ') }} @if ($invoice->period_from)· {{ __('billing.invoices.period') }} {{ $invoice->period_from->format('Y-m-d') }} → {{ $invoice->period_to?->format('Y-m-d') }}@endif</p>
    </header>
    @php($outstanding = $invoice->outstandingCents())
    <div class="grid">
        <article><header>{{ __('billing.invoices.bill_to') }}</header>{{ $invoice->bill_to_name }}<br>{{ $invoice->bill_to_address }}<br>@if ($invoice->bill_to_abn) ABN {{ $invoice->bill_to_abn }} @endif</article>
        <article><header>{{ __('billing.invoices.total') }}</header>
            {{ __('billing.invoices.subtotal') }}: {{ \App\Support\Money::cents((int) round($invoice->subtotal_cents))->format() }}<br>{{ __('billing.invoices.gst') }}: {{ \App\Support\Money::cents((int) round($invoice->gst_cents))->format() }}<br><strong>{{ __('billing.invoices.total') }}: {{ \App\Support\Money::cents((int) round($invoice->total_cents))->format() }} {{ __('billing.money') }}</strong><br>
            {{ __('billing.invoices.paid') }}: {{ \App\Support\Money::cents((int) round($invoice->paid_amount_cents))->format() }} · {{ __('billing.invoices.outstanding') }}: <strong>{{ \App\Support\Money::cents((int) round($outstanding))->format() }}</strong><br>
            {{ __('billing.invoices.issued_at') }}: {{ $invoice->issued_at?->format('Y-m-d') ?? '—' }} · {{ __('billing.invoices.due_at') }}: {{ $invoice->due_at?->format('Y-m-d') ?? '—' }}
        </article>
        <article>
            @if ($invoice->status === 'draft')
                <p class="text-muted"><small>{{ __('billing.invoices.draft_hint') }}</small></p>
                {{-- Audit 2026-09-22 FIN-10 (CR #140): the Job's charges that arrived after this draft was made stay in the pool — list them with 并入本草稿. --}}
                @foreach ($newCharges as $row)
                    <p><small>{{ __('billing.invoices.new_charges_for_job', ['job' => $row['job']?->job_no ?? '—', 'n' => $row['count'], 'amount' => \App\Support\Money::cents($row['amount_cents'])->format()]) }}</small>
                        @if ($row['job'])<form method="post" action="{{ route('billing.invoices.append_job', [$invoice, $row['job']]) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('billing.invoices.append_to_draft') }}</button></form>@endif</p>
                @endforeach
                {{-- CR #142 (lead decision 2026-09-22): an empty draft (every line moved out) stays with a hint; it cannot be issued. --}}
                @if ($invoice->lines->isEmpty())<p><mark>{{ __('billing.invoices.empty_draft_hint') }}</mark></p>@endif
                {{-- Audit 2026-09-22 FIN-03 (CR #132) → CR #142: unpriced revenue of the same Job / period BLOCKS 开出发票 unless the override below is ticked. --}}
                @php($hasUnpriced = $unpricedCharges->isNotEmpty() || $unpricedExceptions->isNotEmpty())
                @if ($hasUnpriced)
                    <div class="erp-warning" role="alert" style="border:1px solid #d9822b;border-radius:.25rem;padding:.6rem .8rem;margin-bottom:.8rem">
                        <strong>{{ __('billing.invoices.review_warning_title', ['n' => $unpricedCharges->count() + $unpricedExceptions->count()]) }}</strong>
                        <ul style="margin:.3rem 0">
                            @foreach ($unpricedCharges as $c)
                                <li><small>{{ __('billing.invoices.review_item', ['id' => $c->id, 'code' => $c->chargeCode->code, 'qty' => rtrim(rtrim(number_format($c->qty, 3), '0'), '.'), 'job' => $c->job?->job_no ?? '—']) }} @if (\App\Modules\Billing\Services\ChargeEngine::isUnpricedMissingRate($c))<span class="badge" data-tone="danger">{{ __('billing.charges.missing_rate_badge') }}</span>@endif</small></li>
                            @endforeach
                            @foreach ($unpricedExceptions as $e)
                                <li><small>{{ __('billing.invoices.exception_item', ['id' => $e->id, 'job' => $e->job?->job_no ?? '—', 'message' => $e->message]) }}</small></li>
                            @endforeach
                        </ul>
                        <small class="text-muted">{{ __('billing.invoices.review_warning_hint') }}
                            @if ($unpricedCharges->isNotEmpty())<a href="{{ route('billing.charges.review', ['client_id' => $invoice->client_id]) }}">{{ __('billing.invoices.review_link') }}</a>@endif
                            @if ($unpricedExceptions->isNotEmpty())· <a href="{{ route('platform.exceptions.index', ['type' => 'missing_rate', 'client_id' => $invoice->client_id]) }}">{{ __('billing.invoices.exceptions_link') }}</a>@endif
                        </small>
                    </div>
                @endif
                <form method="post" action="{{ route('billing.invoices.issue', $invoice) }}">
                    @csrf
                    @if ($hasUnpriced)
                        {{-- The override is explicit and recorded (invoice notes + activity log) — InvoiceService::issue. --}}
                        <label><input type="checkbox" name="unpriced_override" value="1" @checked(old('unpriced_override'))> {{ __('billing.invoices.unpriced_override_label') }}</label>
                        <small class="text-muted">{{ __('billing.invoices.unpriced_override_hint') }}</small>
                    @endif
                    <button type="submit" @disabled($invoice->lines->isEmpty())>{{ __('billing.invoices.issue') }}</button>
                </form>
                <form method="post" action="{{ route('billing.invoices.destroy', $invoice) }}">@csrf @method('DELETE')<button type="submit" class="secondary outline">{{ __('billing.invoices.discard') }}</button></form>
            @else
                <p><a role="button" class="secondary" href="{{ route('billing.invoices.pdf', $invoice) }}" target="_blank">{{ __('billing.invoices.pdf') }}</a></p>
                @if (in_array($invoice->status, ['issued', 'part_paid']) && $outstanding > 0)
                    {{-- Audit 2026-09-22 FIN-13 (CR #140): the amount is pre-filled with the balance and capped at it (the service refuses more). --}}
                    <form method="post" action="{{ route('billing.invoices.payments.store', $invoice) }}">
                        @csrf
                        <div class="grid"><input type="number" step="0.01" min="0.01" max="{{ number_format($outstanding / 100, 2, '.', '') }}" name="amount" value="{{ old('amount', number_format($outstanding / 100, 2, '.', '')) }}" placeholder="{{ __('billing.invoices.payment_amount') }}" aria-label="{{ __('billing.invoices.payment_amount') }}" required><x-date-field name="paid_at" value="{{ today()->toDateString() }}" required /></div>
                        <div class="grid"><select name="method">@foreach (['bank', 'card', 'cash', 'other'] as $m)<option value="{{ $m }}">{{ __('billing.invoices.methods.'.$m) }}</option>@endforeach</select><input type="text" name="reference" placeholder="{{ __('billing.invoices.reference') }}"></div>
                        <small class="text-muted">{{ __('billing.invoices.payment_amount_hint') }}</small>
                        <button type="submit" class="secondary">{{ __('billing.invoices.record_payment') }}</button>
                    </form>
                @endif
            @endif
        </article>
    </div>

    <h2>{{ __('billing.invoices.lines') }}</h2>
    @php($isDraft = $invoice->status === 'draft')
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('billing.charges.code') }}</th><th>{{ __('billing.charges.description') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th>{{ __('billing.charges.uom') }}</th><th class="num">{{ __('billing.charges.amount') }}</th><th class="num">{{ __('billing.invoices.gst') }}</th><th>{{ __('billing.charges.source') }}</th>@if ($isDraft)<th>{{ __('platform.common.actions') }}</th>@endif</tr></thead>
        <tbody>
        @foreach ($groups as $group)
            @php($lines = $group['lines'])
            <tr><td colspan="{{ $isDraft ? 8 : 7 }}"><strong>{{ $group['title'] }}</strong> <small class="text-muted">{{ __('billing.invoices.group_by.'.$invoice->group_by) }}</small></td></tr>
            @foreach ($lines as $l)
                <tr><td><code>{{ $l->charge_code }}</code></td><td>{{ $l->description }}</td><td class="num">{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }}</td><td>{{ $l->uom }}</td><td class="num">{{ \App\Support\Money::cents((int) round($l->amount_cents))->format() }}</td><td class="num">{{ \App\Support\Money::cents((int) round($l->gst_cents))->format() }}</td><td><small>@if ($l->charge)<a href="{{ route('billing.index', ['job_no' => $l->job?->job_no]) }}">#{{ $l->charge_id }}</a> · {{ \Illuminate\Support\Facades\Lang::has('platform.source_types.'.$l->charge->source_type) ? __('platform.source_types.'.$l->charge->source_type) : $l->charge->source_type }} #{{ $l->charge->source_id }}@endif</small></td>
                    {{-- CR #142: a draft line goes back to the unbilled pool one by one (InvoiceService::removeLine); issued lines are reduced by credit notes only. --}}
                    @if ($isDraft)<td><form method="post" action="{{ route('billing.invoices.lines.destroy', [$invoice, $l]) }}" class="inline" onsubmit="return confirm(@js(__('billing.invoices.remove_line_confirm')))">@csrf @method('DELETE')<button type="submit" class="secondary outline">{{ __('billing.invoices.remove_line') }}</button></form></td>@endif
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table></div>
    @if (! $isDraft && filled($invoice->notes))
        <article><header>{{ __('billing.invoices.notes') }}</header><p style="white-space:pre-line">{{ $invoice->notes }}</p></article>
    @endif

    @if ($invoice->status !== 'draft')
        <div class="grid">
            <article>
                <header>{{ __('billing.invoices.payments') }}</header>
                @if ($invoice->payments->isEmpty())
                    <p class="text-muted">—</p>
                @else
                    {{-- Audit 2026-09-22 FIN-13 (CR #140): every row stays; 作废收款 adds a negative twin (作废 #id · reason) and the original shows 已作废. --}}
                    <table class="dense">
                        <thead><tr><th>{{ __('billing.invoices.paid_at') }}</th><th>{{ __('billing.invoices.method') }}</th><th>{{ __('billing.invoices.reference') }}</th><th class="num">{{ __('billing.invoices.payment_amount') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
                        <tbody>
                        @foreach ($invoice->payments->sortBy('id') as $p)
                            <tr>
                                <td>{{ $p->paid_at->format('Y-m-d') }}<br><small class="text-muted">#{{ $p->id }}</small></td>
                                <td>{{ __('billing.invoices.methods.'.$p->method) }}</td>
                                <td>{{ $p->reference }}@if ($p->isVoidRow())<br><small class="text-muted">{{ __('billing.invoices.void_of', ['id' => $p->void_of_payment_id]) }} · {{ $p->note }}</small>@endif</td>
                                <td class="num"><strong>{{ \App\Support\Money::cents((int) round($p->amount_cents))->format() }}</strong></td>
                                <td>
                                    @if ($p->voidedBy)
                                        <span class="badge" data-tone="muted">{{ __('billing.invoices.voided_badge') }}</span>
                                    @elseif (! $p->isVoidRow())
                                        <form method="post" action="{{ route('billing.payments.void', $p) }}" class="inline">@csrf<input type="text" name="reason" placeholder="{{ __('billing.invoices.void_reason') }}" style="width:10rem" required><button type="submit" class="secondary outline">{{ __('billing.invoices.void_payment') }}</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </article>
            <article>
                <header>{{ __('billing.credit_notes.title') }}</header>
                {{-- Audit 2026-09-10: 开出 is gated on the second person's approval (CreditNoteService::issue) — the page shows that state the way the rate-card page does. --}}
                @foreach ($invoice->creditNotes as $n)
                    @php($approved = $creditNoteApproved[$n->id] ?? false)
                    <p>{{ $n->credit_note_no }} · {{ $n->reason }} · {{ \App\Support\Money::cents((int) round(($n->amount_cents + $n->gst_cents)))->format() }} ·
                        @if ($n->status === 'issued')<span class="badge" data-tone="ok">{{ __('billing.credit_notes.statuses.issued') }}</span>
                        @elseif ($approved)<span class="badge" data-tone="ok">{{ __('billing.credit_notes.approved_badge') }}</span>
                        @else<span class="badge" data-tone="warn">{{ __('billing.credit_notes.statuses.draft') }}</span> <small><a href="{{ route('platform.approvals.index', ['type' => 'credit_note']) }}">{{ in_array($n->id, $creditNotePending, true) ? __('billing.credit_notes.pending_hint') : __('billing.credit_notes.no_approval_hint') }}</a></small>@endif
                        @if ($n->status !== 'issued')<form method="post" action="{{ route('billing.credit_notes.issue', $n) }}" class="inline">@csrf<button type="submit" class="secondary outline" @disabled(! $approved)>{{ __('billing.credit_notes.issue') }}</button></form>@endif</p>
                @endforeach
                {{-- The form reopens with what was typed after a refusal (audit 2026-09-10); 去开冲减单 from the charges list opens it with that line's remaining amount filled in (FIN-16, CR #140). --}}
                <span id="credit-note"></span>
                <details{{ $errors->has('reason') || $errors->has('lines') || $errors->has('lines.*') || $creditLine ? ' open' : '' }}>
                    <summary>{{ __('billing.credit_notes.new') }}</summary>
                    @if ($creditLine)<p class="text-muted"><small>{{ __('billing.credit_notes.preselected_hint') }}</small></p>@endif
                    <form method="post" action="{{ route('billing.invoices.credit_notes.store', $invoice) }}">
                        @csrf
                        <input type="text" name="reason" placeholder="{{ __('billing.credit_notes.reason') }}" value="{{ old('reason') }}" required>
                        @foreach ($invoice->lines as $i => $l)
                            @php($remaining = max(0, (int) $l->amount_cents - (int) ($creditedByLine[$l->id] ?? 0)))
                            <div class="grid"><span>{{ $l->charge_code }} · {{ $l->description }} ({{ \App\Support\Money::cents((int) round($l->amount_cents))->format() }}) <small class="text-muted">{{ __('billing.credit_notes.remaining', ['amount' => \App\Support\Money::cents($remaining)->format()]) }}</small></span><input type="hidden" name="lines[{{ $i }}][invoice_line_id]" value="{{ $l->id }}"><input type="number" step="0.01" min="0" max="{{ number_format($remaining / 100, 2, '.', '') }}" name="lines[{{ $i }}][amount]" placeholder="{{ __('billing.credit_notes.line_amount') }}" value="{{ old("lines.$i.amount", $creditLine === (int) $l->id && $remaining > 0 ? number_format($remaining / 100, 2, '.', '') : '') }}"@if ($creditLine === (int) $l->id) autofocus data-preselected="1"@endif></div>
                        @endforeach
                        <button type="submit" class="secondary">{{ __('billing.credit_notes.new') }}</button>
                    </form>
                </details>
            </article>
        </div>
    @endif
@endsection
