@extends('layouts.app')

@section('title', $quote->quote_no)

@section('content')
    <p><a href="{{ route('billing.quotes.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $quote->quote_no }} <span class="badge" data-tone="{{ ['draft' => 'muted', 'sent' => 'warn', 'accepted' => 'ok', 'rejected' => 'danger', 'expired' => 'muted'][$quote->status] }}">{{ __('billing.quotes.statuses.'.$quote->status) }}</span></h1>
        <p>{{ $quote->client->name }} · {{ __('billing.quotes.stages.'.$quote->stage) }} · {{ __('billing.quotes.valid_until') }} {{ $quote->valid_until?->format('Y-m-d') }} · {{ $quote->notes }}</p>
    </header>
    <table class="dense">
        <thead><tr><th>{{ __('billing.charges.code') }}</th><th>{{ __('billing.charges.description') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th>{{ __('billing.charges.uom') }}</th><th class="num">{{ __('billing.charges.amount') }}</th><th>{{ __('billing.quotes.assumptions') }}</th></tr></thead>
        <tbody>
        @foreach ($quote->lines as $l)
            <tr><td><code>{{ $l->charge_code }}</code></td><td>{{ $l->description }}</td><td class="num">{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }}</td><td>{{ $l->uom }}</td><td class="num">{{ \App\Support\Money::cents((int) round($l->amount_cents))->format() }} @if (($l->assumptions['is_poa'] ?? false) || ($l->assumptions['missing_rate'] ?? false))<br><span class="badge" data-tone="danger">{{ __('billing.quotes.poa_flag') }}</span>@endif</td><td><small><code>{{ json_encode(collect($l->assumptions)->except(['calculation'])->all(), JSON_UNESCAPED_UNICODE) }}</code></small></td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="4"><strong>{{ __('billing.quotes.total') }}</strong></td><td class="num"><strong>{{ \App\Support\Money::cents((int) round($quote->total_cents))->format() }}</strong> <small class="text-muted">({{ __('billing.invoices.gst') }} {{ \App\Support\Money::cents((int) round($quote->gst_cents))->format() }})</small></td><td></td></tr></tfoot>
    </table>
    <form method="post" action="{{ route('billing.quotes.status', $quote) }}" class="grid">
        @csrf
        <select name="status">@foreach ($statuses as $s)<option value="{{ $s }}" @selected($quote->status === $s)>{{ __('billing.quotes.statuses.'.$s) }}</option>@endforeach</select>
        <button type="submit" class="secondary">{{ __('platform.common.save') }}</button>
    </form>
@endsection
