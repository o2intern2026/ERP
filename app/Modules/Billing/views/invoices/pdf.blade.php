<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; }
        h1 { font-size: 20pt; margin: 0 0 6px; letter-spacing: 1px; }
        table.head { width: 100%; margin-bottom: 10px; }
        table.head td { vertical-align: top; padding: 0; }
        table.head td.right { text-align: right; width: 42%; }
        .seller .name { font-size: 14pt; font-weight: bold; }
        .seller .abn { font-size: 12pt; font-weight: bold; margin: 2px 0 4px; }
        .billto { margin-top: 8px; display: inline-block; text-align: left; }
        table.meta { width: 100%; border-top: 1px solid #999; border-bottom: 1px solid #999; margin-bottom: 12px; }
        table.meta td { padding: 4px 6px 4px 0; vertical-align: top; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th, table.lines td { border-bottom: 1px solid #ccc; padding: 4px 6px; text-align: left; vertical-align: top; }
        table.lines th.num, table.lines td.num { text-align: right; }
        .job { background: #f2f2f2; font-weight: bold; }
        table.totals { margin-top: 12px; width: 42%; margin-left: 58%; border-collapse: collapse; }
        table.totals td { padding: 3px 6px; }
        table.totals td.num { text-align: right; }
        table.totals tr.due td { border-top: 1px solid #999; font-weight: bold; }
        table.pay { margin-top: 16px; width: 56%; border: 1px solid #999; border-collapse: collapse; }
        table.pay th { text-align: left; padding: 5px 8px; background: #f2f2f2; border-bottom: 1px solid #999; }
        table.pay td { padding: 3px 8px; }
        table.pay td.label { color: #555; width: 38%; }
        .small { color: #666; font-size: 8.5pt; }
    </style>
</head>
<body>
    @php
        // clients.payment_terms is prepaid | eom | net_N (Enums::PAYMENT_TERMS_PATTERN); only the due date depends on it.
        $terms = (string) $invoice->client->payment_terms;
        $termsLabel = preg_match('/^net_(\d+)$/', $terms, $m) ? __('pdf.payment_terms.net', ['days' => (int) $m[1]]) : __('pdf.payment_terms.'.$terms);
        $money = fn (int $cents) => \App\Support\Money::cents($cents)->format();
        $credited = $invoice->creditedCents();
        $paid = (int) $invoice->paid_amount_cents;
    @endphp
    {{-- Seller top-left with the ABN prominent (a tax invoice must identify the supplier), TAX INVOICE + Bill to on the right — audit 2026-09-22 FIN-01 / GAP-04. --}}
    <table class="head">
        <tr>
            <td class="seller">
                <div class="name">{{ $company['name'] }}</div>
                <div class="abn">{{ __('pdf.invoice.abn') }} {{ $company['abn'] }}</div>
                <div>{{ $company['address'] }}</div>
                <div class="small">@if ($company['phone']){{ __('pdf.invoice.phone') }} {{ $company['phone'] }}@endif @if ($company['phone'] && $company['email']) · @endif @if ($company['email']){{ __('pdf.invoice.email') }} {{ $company['email'] }}@endif</div>
            </td>
            <td class="right">
                <h1>{{ __('pdf.invoice.title') }}</h1>
                <div class="billto"><strong>{{ __('pdf.invoice.bill_to') }}</strong><br>{{ $invoice->bill_to_name }}<br>{{ $invoice->bill_to_address }}<br>@if ($invoice->bill_to_abn){{ __('pdf.invoice.abn') }} {{ $invoice->bill_to_abn }}@endif</div>
            </td>
        </tr>
    </table>
    <table class="meta">
        <tr>
            <td><strong>{{ __('pdf.invoice.invoice_no') }}</strong><br>{{ $invoice->invoice_no }}</td>
            <td><strong>{{ __('pdf.invoice.invoice_date') }}</strong><br>{{ $invoice->issued_at?->format('d M Y') }}</td>
            <td><strong>{{ __('pdf.invoice.due') }}</strong><br>{{ $invoice->due_at?->format('d M Y') }} ({{ $termsLabel }})</td>
            <td><strong>{{ __('pdf.invoice.type') }}</strong><br>{{ __('pdf.invoice_types.'.$invoice->invoice_type) }}@if ($invoice->period_from) · {{ __('pdf.invoice.period') }} {{ $invoice->period_from->format('d M') }} – {{ $invoice->period_to?->format('d M Y') }}@endif</td>
        </tr>
    </table>
    <table class="lines">
        <thead><tr><th>{{ __('pdf.invoice.code') }}</th><th>{{ __('pdf.invoice.description') }}</th><th class="num">{{ __('pdf.invoice.qty') }}</th><th>{{ __('pdf.invoice.uom') }}</th><th class="num">{{ __('pdf.invoice.rate') }}</th><th class="num">{{ __('pdf.invoice.amount_ex_gst') }}</th><th class="num">{{ __('pdf.invoice.gst') }}</th></tr></thead>
        <tbody>
        @foreach ($groups as $group)
            @php($lines = $group['lines'])
            <tr class="job"><td colspan="7">{{ $invoice->group_by === 'order' ? __('pdf.invoice.order') : __('pdf.invoice.job') }} {{ $group['title'] }}</td></tr>
            @foreach ($lines as $line)
                {{-- The source document (order no / mark / ASN no / container no) instead of the internal charge id; the rate from the charge's rate snapshot. --}}
                <tr>
                    <td>{{ $line->charge_code }}</td>
                    <td>{{ $line->description }}@if (! empty($refs[$line->id])) <span class="small">@foreach ($refs[$line->id] as $i => $ref)@if ($i > 0) · @endif{{ __('pdf.invoice.'.$ref[0]) }} {{ $ref[1] }}@endforeach</span>@endif</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line->qty, 3), '0'), '.') }}</td>
                    <td>{{ $line->uom ? __('pdf.uoms.'.$line->uom) : '' }}</td>
                    <td class="num">{{ $line->charge?->rate_snapshot_cents !== null ? $money((int) $line->charge->rate_snapshot_cents) : '—' }}</td>
                    <td class="num">{{ $money((int) round($line->amount_cents)) }}</td>
                    <td class="num">{{ $money((int) round($line->gst_cents)) }}</td>
                </tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>{{ __('pdf.invoice.subtotal') }}</td><td class="num">{{ $money((int) round($invoice->subtotal_cents)) }}</td></tr>
        <tr><td>{{ __('pdf.invoice.gst') }}</td><td class="num">{{ $money((int) round($invoice->gst_cents)) }}</td></tr>
        <tr><td><strong>{{ __('pdf.invoice.total') }}</strong></td><td class="num"><strong>{{ $money((int) round($invoice->total_cents)) }}</strong></td></tr>
        @if ($credited > 0 || $paid > 0)
            @if ($credited > 0)<tr><td>{{ __('pdf.invoice.less_credit_notes') }}</td><td class="num">−{{ $money($credited) }}</td></tr>@endif
            @if ($paid > 0)<tr><td>{{ __('pdf.invoice.paid') }}</td><td class="num">−{{ $money($paid) }}</td></tr>@endif
            <tr class="due"><td>{{ __('pdf.invoice.balance_due') }}</td><td class="num">{{ $money($invoice->outstandingCents()) }}</td></tr>
        @endif
    </table>
    <table class="pay">
        <tr><th colspan="2">{{ __('pdf.invoice.how_to_pay') }}</th></tr>
        <tr><td class="label">{{ __('pdf.invoice.bank') }}</td><td>{{ $company['bank_name'] }}</td></tr>
        <tr><td class="label">{{ __('pdf.invoice.bsb') }}</td><td>{{ $company['bsb'] }}</td></tr>
        <tr><td class="label">{{ __('pdf.invoice.account_no') }}</td><td>{{ $company['account_no'] }}</td></tr>
        <tr><td class="label">{{ __('pdf.invoice.account_name') }}</td><td>{{ $company['account_name'] }}</td></tr>
        <tr><td class="label">{{ __('pdf.invoice.payment_reference') }}</td><td><strong>{{ $invoice->invoice_no }}</strong></td></tr>
    </table>
    <p class="small">{{ __('pdf.invoice.footer') }}</p>
</body>
</html>
