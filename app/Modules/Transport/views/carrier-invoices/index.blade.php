@extends('layouts.app')

@section('title', __('transport.reconciliation.title'))

@section('content')
    <h1>{{ __('transport.reconciliation.title') }}</h1>

    <details open>
        <summary>{{ __('transport.reconciliation.import_title') }}</summary>
        <p>{{ __('transport.reconciliation.csv_help') }}</p>
        <form method="post" action="{{ route('transport.carrier-invoices.store') }}" enctype="multipart/form-data">
            @csrf
            <label>
                {{ __('transport.reconciliation.carrier') }}
                <select name="carrier_id" required>
                    <option value="">{{ __('transport.reconciliation.choose_carrier') }}</option>
                    @foreach ($carriers as $carrier)
                        <option value="{{ $carrier->id }}" @selected((string) old('carrier_id') === (string) $carrier->id)>{{ $carrier->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('transport.reconciliation.invoice_no') }}<input name="invoice_no" value="{{ old('invoice_no') }}" required></label>
            <label>{{ __('transport.reconciliation.period_from') }}<input type="date" name="period_from" value="{{ old('period_from') }}" required></label>
            <label>{{ __('transport.reconciliation.period_to') }}<input type="date" name="period_to" value="{{ old('period_to') }}" required></label>
            <label>{{ __('transport.reconciliation.total_cents') }}<input type="number" name="total_cents" min="0" step="1" value="{{ old('total_cents') }}" required></label>
            <label>{{ __('transport.reconciliation.statement') }}<input type="file" name="statement" accept=".csv,text/csv" required></label>
            <button type="submit">{{ __('transport.reconciliation.import') }}</button>
        </form>
    </details>

    @if ($invoices->isEmpty())
        <p>{{ __('transport.reconciliation.empty') }}</p>
    @else
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('transport.reconciliation.invoice_no') }}</th>
                    <th>{{ __('transport.reconciliation.carrier') }}</th>
                    <th>{{ __('transport.reconciliation.period') }}</th>
                    <th class="num">{{ __('transport.reconciliation.total') }}</th>
                    <th>{{ __('transport.reconciliation.status') }}</th>
                    <th class="num">{{ __('transport.reconciliation.line_count') }}</th>
                    <th class="num">{{ __('transport.reconciliation.difference_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    @php($differenceCount = $invoice->difference_count + ((int) $invoice->lines_sum_billed_cents !== $invoice->total_cents ? 1 : 0))
                    <tr>
                        <td><a href="{{ route('transport.carrier-invoices.show', $invoice) }}">{{ $invoice->invoice_no }}</a></td>
                        <td>{{ $invoice->carrier->name }}</td>
                        <td>{{ $invoice->period_from->toDateString() }} — {{ $invoice->period_to->toDateString() }}</td>
                        <td class="num">{{ \App\Support\Money::cents($invoice->total_cents)->format() }}</td>
                        <td><span class="badge" data-tone="{{ $invoice->status === 'matched' ? 'ok' : ($invoice->status === 'disputed' ? 'warn' : '') }}">{{ __('transport.reconciliation.statuses.'.$invoice->status) }}</span></td>
                        <td class="num">{{ $invoice->lines_count }}</td>
                        <td class="num">{{ $differenceCount }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        {{ $invoices->links() }}
    @endif
@endsection
