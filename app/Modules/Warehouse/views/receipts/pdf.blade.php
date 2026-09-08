<!doctype html>
<html lang="zh">
<head>
    <meta charset="utf-8">
    <style>
        {{-- One CJK face, normal weight only: dompdf falls back to another family when a weight is missing, so nothing here is bold.
             The CSS below is emitted unescaped on purpose ({{ }} would turn the quotes into &#039; and break the font-family). --}}
        @if ($cjkFont)
        @@font-face { font-family: "ERP CJK"; font-weight: normal; font-style: normal; src: url("file://{!! $cjkFont !!}") format("truetype"); }
        @endif
        @@page { margin: 12mm 12mm 14mm; }
        body { font-family: {!! $cjkFont ? '"ERP CJK", ' : '' !!}"DejaVu Sans", sans-serif; font-size: 9pt; color: #222; }
        h1, h2, th, strong, b, tfoot td, .draft { font-weight: normal; }
        h1 { font-size: 18pt; margin: 0 0 2px; }
        h2 { font-size: 11.5pt; margin: 14px 0 4px; border-bottom: 1px solid #999; padding-bottom: 2px; }
        p.sub strong { font-size: 11pt; }
        .sub { color: #666; font-size: 9pt; margin: 0 0 10px; }
        .badge { display: inline-block; border: 1px solid #c62828; color: #c62828; padding: 1px 6px; border-radius: 3px; font-size: 8.5pt; margin-left: 6px; }
        .draft { position: fixed; top: 38%; left: 12%; font-size: 72pt; color: rgba(200, 40, 40, .12); transform: rotate(-20deg); }
        table.meta { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
        table.meta td { vertical-align: top; padding: 2px 6px 2px 0; width: 25%; }
        table.meta td strong { color: #555; font-weight: normal; font-size: 8.5pt; display: block; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th, table.lines td { border-bottom: 1px solid #ccc; padding: 3px 4px; text-align: left; vertical-align: top; }
        table.lines th { background: #f2f2f2; font-size: 8.5pt; }
        table.lines th.num, table.lines td.num { text-align: right; }
        table.lines .w-idx { width: 3%; } table.lines .w-mark { width: 12%; } table.lines .w-desc { width: 20%; } table.lines .w-cont { width: 11%; }
        table.lines .w-qty { width: 5%; } table.lines .w-reason { width: 14%; } table.lines .w-labels { width: 15%; }
        table.lines td.labels { white-space: nowrap; }
        table.lines tfoot td { border-top: 2px solid #999; background: #f7f7f7; }
        .labels { font-size: 7pt; color: #555; }
        table.rollup { border-collapse: collapse; margin-top: 4px; }
        table.rollup td { padding: 2px 14px 2px 0; }
        table.rollup strong { font-size: 11pt; }
        table.sign { width: 100%; margin-top: 28px; border-collapse: collapse; }
        table.sign td { width: 33%; padding: 0 12px 0 0; }
        table.sign .line { border-bottom: 1px solid #333; height: 34px; }
        table.sign .cap { font-size: 8.5pt; color: #555; padding-top: 3px; }
        .small { color: #666; font-size: 8pt; margin-top: 10px; }
    </style>
</head>
<body>
    @if ($receipt->isOpen())<div class="draft">{{ __('warehouse.receipts.pdf.draft') }}</div>@endif
    <h1>{{ __('warehouse.receipts.pdf.title') }} @if ($receipt->unplanned)<span class="badge">{{ __('warehouse.receipts.pdf.unplanned_badge') }}</span>@endif @if ($receipt->isOpen())<span class="badge">{{ __('warehouse.receipts.pdf.draft') }}</span>@endif</h1>
    <p class="sub">{{ __('warehouse.receipts.pdf.receipt_no') }} <strong>{{ $receipt->receipt_no }}</strong> · {{ __('warehouse.receipts.pdf.asn_no') }} {{ $asn->asn_no }} · {{ __('warehouse.receipts.pdf.batch') }} {{ $receipt->batch_no }}/{{ $totalBatches }}</p>

    <table class="meta">
        <tr>
            <td><strong>{{ __('warehouse.receipts.pdf.client') }}</strong>{{ $receipt->client->name }}@if ($receipt->client->abn)<br>ABN {{ $receipt->client->abn }}@endif</td>
            <td><strong>{{ __('warehouse.receipts.pdf.warehouse') }}</strong>{{ $receipt->warehouse->code }} · {{ $receipt->warehouse->name }}</td>
            <td><strong>{{ __('warehouse.receipts.pdf.job') }}</strong>{{ $receipt->job?->job_no ?? '—' }}</td>
            <td><strong>{{ __('warehouse.receipts.pdf.inbound_type') }}</strong>{{ __('warehouse.inbound_types.'.$asn->inbound_type) }}@if ($asn->containers->isNotEmpty()) · {{ $asn->containers->pluck('container_no')->implode(', ') }}@endif</td>
        </tr>
        <tr>
            <td><strong>{{ __('warehouse.receipts.pdf.arrived_at') }}</strong>{{ ($asn->arrived_at ?? $receipt->opened_at)?->format('Y-m-d') }}</td>
            <td><strong>{{ __('warehouse.receipts.pdf.delivery_reference') }}</strong>{{ $receipt->delivery_reference ?? '—' }}</td>
            <td><strong>{{ __('warehouse.receipts.pdf.receiver') }}</strong>{{ $receipt->completedBy?->name ?? $receipt->openedBy?->name ?? '—' }}</td>
            <td><strong>{{ __('warehouse.receipts.pdf.printed_at') }}</strong>{{ now()->format('Y-m-d H:i') }}@if ($receipt->completed_at)<br><strong>{{ __('warehouse.receipts.pdf.completed_at') }}</strong>{{ $receipt->completed_at->format('Y-m-d H:i') }}@endif</td>
        </tr>
    </table>

    <table class="lines">
        <thead><tr>
            <th class="w-idx">#</th><th class="w-mark">{{ __('warehouse.receipts.pdf.mark') }}</th><th class="w-desc">{{ __('warehouse.receipts.pdf.description') }}</th><th class="w-cont">{{ __('warehouse.receipts.pdf.container') }}</th>
            <th class="num w-qty">{{ __('warehouse.receipts.pdf.expected') }}</th><th class="num w-qty">{{ __('warehouse.receipts.pdf.received') }}</th><th class="num w-qty">{{ __('warehouse.receipts.pdf.damaged') }}</th><th class="num w-qty">{{ __('warehouse.receipts.pdf.variance') }}</th>
            <th class="w-reason">{{ __('warehouse.receipts.pdf.variance_reason') }}</th><th class="num w-qty">{{ __('warehouse.receipts.pdf.pallets') }}</th><th class="w-labels">{{ __('warehouse.receipts.pdf.unit_labels') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($receipt->lines as $l)
            <tr>
                <td>{{ $loop->iteration }}</td><td>{{ $l->asnLine?->consignment_mark }}</td><td>{{ $l->asnLine?->description }}</td><td>{{ $l->asnLine?->container?->container_no }}</td>
                <td class="num">{{ $l->expected_cartons }}</td><td class="num">{{ $l->received_cartons }}</td><td class="num">{{ $l->damaged_cartons }}</td>
                <td class="num">{{ $l->variance() > 0 ? '+' : '' }}{{ $l->variance() }}</td><td>{{ $l->variance_reason }}</td><td class="num">{{ $l->pallet_count }}</td>
                <td class="labels">@foreach ($labels->get($l->asn_line_id, []) as $code){{ $code }}@if (! $loop->last)<br>@endif @endforeach</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr>
            <td colspan="4">{{ __('warehouse.receipts.pdf.totals') }} ({{ $receipt->lines->count() }} {{ __('warehouse.receipts.pdf.lines_suffix') }})</td>
            <td class="num">{{ $receipt->lines->sum('expected_cartons') }}</td><td class="num">{{ $receipt->lines->sum('received_cartons') }}</td><td class="num">{{ $receipt->lines->sum('damaged_cartons') }}</td>
            <td class="num">@php($v = $receipt->lines->sum(fn ($l) => $l->variance())){{ $v > 0 ? '+' : '' }}{{ $v }}</td><td></td><td class="num">{{ $receipt->lines->sum('pallet_count') }}</td><td class="labels">{{ $receipt->lines->sum('unit_count') }} {{ __('warehouse.receipts.pdf.units_suffix') }}</td>
        </tr></tfoot>
    </table>

    <h2>{{ __('warehouse.receipts.pdf.rollup_title') }} · {{ $asn->asn_no }} ({{ $totalBatches }} {{ __('warehouse.receipts.pdf.batches_suffix') }})</h2>
    <table class="rollup"><tr>
        <td>{{ __('warehouse.receipts.pdf.expected') }} <strong>{{ $rollup['expected'] }}</strong></td>
        <td>{{ __('warehouse.receipts.pdf.received') }} <strong>{{ $rollup['received'] }}</strong></td>
        <td>{{ __('warehouse.receipts.pdf.damaged') }} <strong>{{ $rollup['damaged'] }}</strong></td>
        <td>{{ __('warehouse.receipts.pdf.variance') }} <strong>{{ $rollup['variance'] > 0 ? '+' : '' }}{{ $rollup['variance'] }}</strong></td>
        <td>{{ __('warehouse.receipts.pdf.lines_received', ['done' => $rollup['received_lines'], 'total' => $rollup['total_lines']]) }}</td>
    </tr></table>

    <table class="sign"><tr>
        <td><div class="line"></div><div class="cap">{{ __('warehouse.receipts.pdf.sign_warehouse') }} · {{ __('warehouse.receipts.pdf.date') }} ____ / ____ / ________</div></td>
        <td><div class="line"></div><div class="cap">{{ __('warehouse.receipts.pdf.sign_driver') }} · {{ __('warehouse.receipts.pdf.date') }} ____ / ____ / ________</div></td>
        <td><div class="line"></div><div class="cap">{{ __('warehouse.receipts.pdf.sign_client') }} · {{ __('warehouse.receipts.pdf.date') }} ____ / ____ / ________</div></td>
    </tr></table>
    <p class="small">{{ __('warehouse.receipts.pdf.footer') }}</p>
</body>
</html>
