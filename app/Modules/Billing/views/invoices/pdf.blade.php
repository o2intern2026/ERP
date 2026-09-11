<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #222; }
        h1 { font-size: 18pt; margin: 0 0 4px; }
        .meta { width: 100%; margin-bottom: 14px; }
        .meta td { vertical-align: top; padding: 2px 6px 2px 0; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th, table.lines td { border-bottom: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        table.lines th.num, table.lines td.num { text-align: right; }
        .job { background: #f2f2f2; font-weight: bold; }
        .totals { margin-top: 12px; width: 40%; margin-left: 60%; }
        .totals td { padding: 3px 6px; }
        .totals td.num { text-align: right; }
        .small { color: #666; font-size: 8.5pt; }
    </style>
</head>
<body>
    @php
        // clients.payment_terms is prepaid | eom | net_N (Enums::PAYMENT_TERMS_PATTERN); only the due date depends on it.
        $terms = (string) $invoice->client->payment_terms;
        $termsLabel = preg_match('/^net_(\d+)$/', $terms, $m) ? __('pdf.payment_terms.net', ['days' => (int) $m[1]]) : __('pdf.payment_terms.'.$terms);
    @endphp
    <h1>{{ __('pdf.invoice.title') }} {{ $invoice->invoice_no }}</h1>
    <table class="meta">
        <tr>
            <td style="width:50%"><strong>{{ __('pdf.invoice.bill_to') }}</strong><br>{{ $invoice->bill_to_name }}<br>{{ $invoice->bill_to_address }}<br>@if ($invoice->bill_to_abn) ABN {{ $invoice->bill_to_abn }} @endif</td>
            <td><strong>{{ __('pdf.invoice.invoice_date') }}</strong> {{ $invoice->issued_at?->format('d M Y') }}<br><strong>{{ __('pdf.invoice.due') }}</strong> {{ $invoice->due_at?->format('d M Y') }} ({{ $termsLabel }})<br><strong>{{ __('pdf.invoice.type') }}</strong> {{ __('pdf.invoice_types.'.$invoice->invoice_type) }} @if ($invoice->period_from) · {{ $invoice->period_from->format('d M') }} – {{ $invoice->period_to?->format('d M Y') }} @endif</td>
        </tr>
    </table>
    <table class="lines">
        <thead><tr><th>{{ __('pdf.invoice.code') }}</th><th>{{ __('pdf.invoice.description') }}</th><th class="num">{{ __('pdf.invoice.qty') }}</th><th>{{ __('pdf.invoice.uom') }}</th><th class="num">{{ __('pdf.invoice.amount_ex_gst') }}</th><th class="num">{{ __('pdf.invoice.gst') }}</th></tr></thead>
        <tbody>
        @foreach ($groups as $group)
            @php($lines = $group['lines'])
            <tr class="job"><td colspan="6">{{ $invoice->group_by === 'order' ? __('pdf.invoice.order') : __('pdf.invoice.job') }} {{ $group['title'] }}</td></tr>
            @foreach ($lines as $line)
                <tr><td>{{ $line->charge_code }}</td><td>{{ $line->description }} <span class="small">#{{ $line->charge_id }}</span></td><td class="num">{{ rtrim(rtrim(number_format($line->qty, 3), '0'), '.') }}</td><td>{{ $line->uom ? __('pdf.uoms.'.$line->uom) : '' }}</td><td class="num">{{ \App\Support\Money::cents((int) round($line->amount_cents))->format() }}</td><td class="num">{{ \App\Support\Money::cents((int) round($line->gst_cents))->format() }}</td></tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>{{ __('pdf.invoice.subtotal') }}</td><td class="num">{{ \App\Support\Money::cents((int) round($invoice->subtotal_cents))->format() }}</td></tr>
        <tr><td>{{ __('pdf.invoice.gst') }}</td><td class="num">{{ \App\Support\Money::cents((int) round($invoice->gst_cents))->format() }}</td></tr>
        <tr><td><strong>{{ __('pdf.invoice.total') }}</strong></td><td class="num"><strong>{{ \App\Support\Money::cents((int) round($invoice->total_cents))->format() }}</strong></td></tr>
    </table>
    <p class="small">{{ __('pdf.invoice.footer') }}</p>
</body>
</html>
