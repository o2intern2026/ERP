@extends('layouts.app')

@section('title', __('transport.reconciliation.invoice_title', ['invoice' => $invoice->invoice_no]))

@section('content')
    <p><a href="{{ route('transport.carrier-invoices.index') }}">{{ __('transport.reconciliation.back') }}</a></p>
    <h1>{{ __('transport.reconciliation.invoice_title', ['invoice' => $invoice->invoice_no]) }}</h1>

    <dl>
        <dt>{{ __('transport.reconciliation.carrier') }}</dt><dd>{{ $invoice->carrier->name }}</dd>
        <dt>{{ __('transport.reconciliation.period') }}</dt><dd>{{ $invoice->period_from->toDateString() }} — {{ $invoice->period_to->toDateString() }}</dd>
        <dt>{{ __('transport.reconciliation.total') }}</dt><dd>{{ \App\Support\Money::cents($invoice->total_cents)->format() }}</dd>
        <dt>{{ __('transport.reconciliation.line_total') }}</dt><dd>{{ \App\Support\Money::cents($lineTotalCents)->format() }}</dd>
        <dt>{{ __('transport.reconciliation.total_variance') }}</dt><dd>{{ \App\Support\Money::cents($totalVarianceCents)->format() }}</dd>
        <dt>{{ __('transport.reconciliation.status') }}</dt><dd>{{ __('transport.reconciliation.statuses.'.$invoice->status) }}</dd>
    </dl>

    @if ($totalVarianceCents !== 0)
        <p><span class="badge" data-tone="warn">{{ __('transport.reconciliation.invoice_total_mismatch') }}</span></p>
    @endif

    <p><a role="button" href="{{ route('transport.carrier-invoices.differences', $invoice) }}">{{ __('transport.reconciliation.export_differences') }}</a></p>

    <table class="dense">
        <thead>
            <tr>
                <th>{{ __('transport.reconciliation.tracking_number') }}</th>
                <th>{{ __('transport.reconciliation.shipment') }}</th>
                <th class="num">{{ __('transport.reconciliation.billed') }}</th>
                <th class="num">{{ __('transport.reconciliation.expected') }}</th>
                <th class="num">{{ __('transport.reconciliation.variance') }}</th>
                <th>{{ __('transport.reconciliation.result') }}</th>
                <th>{{ __('transport.reconciliation.note') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr data-tone="{{ $line->matched ? 'ok' : 'warn' }}">
                    <td>{{ $line->tracking_number }}</td>
                    <td>
                        @if ($line->shipment)
                            <a href="{{ route('transport.shipments.show', $line->shipment) }}">{{ $line->shipment->shipment_no }}</a>
                        @else
                            {{ __('transport.reconciliation.unmatched') }}
                        @endif
                    </td>
                    <td class="num">{{ \App\Support\Money::cents($line->billed_cents)->format() }}</td>
                    <td class="num">{{ \App\Support\Money::cents($line->expected_cents)->format() }}</td>
                    <td class="num">{{ \App\Support\Money::cents($line->variance_cents)->format() }}</td>
                    <td><span class="badge" data-tone="{{ $line->matched ? 'ok' : 'warn' }}">{{ $line->matched ? __('transport.reconciliation.line_matched') : __('transport.reconciliation.line_disputed') }}</span></td>
                    <td>{{ $line->note ?: __('transport.reconciliation.no_note') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
