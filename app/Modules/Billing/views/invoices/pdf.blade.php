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
    <h1>TAX INVOICE {{ $invoice->invoice_no }}</h1>
    <table class="meta">
        <tr>
            <td style="width:50%"><strong>Bill to</strong><br>{{ $invoice->bill_to_name }}<br>{{ $invoice->bill_to_address }}<br>@if ($invoice->bill_to_abn) ABN {{ $invoice->bill_to_abn }} @endif</td>
            <td><strong>Invoice date</strong> {{ $invoice->issued_at?->format('d M Y') }}<br><strong>Due</strong> {{ $invoice->due_at?->format('d M Y') }} ({{ $invoice->client->payment_terms }})<br><strong>Type</strong> {{ $invoice->invoice_type }} @if ($invoice->period_from) · {{ $invoice->period_from->format('d M') }} – {{ $invoice->period_to?->format('d M Y') }} @endif</td>
        </tr>
    </table>
    <table class="lines">
        <thead><tr><th>Code</th><th>Description</th><th class="num">Qty</th><th>UOM</th><th class="num">Amount (ex GST)</th><th class="num">GST</th></tr></thead>
        <tbody>
        @foreach ($groups as $group)
            @php($lines = $group['lines'])
            <tr class="job"><td colspan="6">{{ $invoice->group_by === 'order' ? 'Order' : 'Job' }} {{ $group['title'] }}</td></tr>
            @foreach ($lines as $line)
                <tr><td>{{ $line->charge_code }}</td><td>{{ $line->description }} <span class="small">#{{ $line->charge_id }}</span></td><td class="num">{{ rtrim(rtrim(number_format($line->qty, 3), '0'), '.') }}</td><td>{{ $line->uom }}</td><td class="num">{{ number_format($line->amount_cents / 100, 2) }}</td><td class="num">{{ number_format($line->gst_cents / 100, 2) }}</td></tr>
            @endforeach
        @endforeach
        </tbody>
    </table>
    <table class="totals">
        <tr><td>Subtotal (ex GST)</td><td class="num">{{ number_format($invoice->subtotal_cents / 100, 2) }}</td></tr>
        <tr><td>GST</td><td class="num">{{ number_format($invoice->gst_cents / 100, 2) }}</td></tr>
        <tr><td><strong>Total AUD</strong></td><td class="num"><strong>{{ number_format($invoice->total_cents / 100, 2) }}</strong></td></tr>
    </table>
    <p class="small">Every line refers to a charge (#) that links back to its source document in the ERP. Amounts in AUD; GST at 10% where applicable.</p>
</body>
</html>
