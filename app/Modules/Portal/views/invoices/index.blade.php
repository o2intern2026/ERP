@extends('layouts.app')

@section('title', __('portal.invoices.title'))

@section('content')
    <header>
        <h1>{{ __('portal.invoices.title') }}</h1>
        <p class="text-muted"><small>{{ __('portal.invoices.hint') }}</small></p>
    </header>

    @if ($invoices->isEmpty())
        <p>{{ __('portal.invoices.empty') }}</p>
    @else
        <p><strong>{{ __('portal.invoices.outstanding') }}:</strong> {{ \App\Support\Money::cents($outstandingCents)->format() }}</p>
        <div class="overflow-auto">
            <table class="dense">
                <thead><tr>
                    <th>{{ __('portal.invoices.fields.invoice_no') }}</th>
                    <th>{{ __('portal.invoices.fields.type') }}</th>
                    <th>{{ __('portal.invoices.fields.period') }}</th>
                    <th>{{ __('portal.invoices.fields.issued_at') }}</th>
                    <th>{{ __('portal.invoices.fields.due_at') }}</th>
                    <th class="num">{{ __('portal.invoices.fields.subtotal') }}</th>
                    <th class="num">{{ __('portal.invoices.fields.gst') }}</th>
                    <th class="num">{{ __('portal.invoices.fields.total') }}</th>
                    <th class="num">{{ __('portal.invoices.fields.paid') }}</th>
                    <th>{{ __('portal.invoices.fields.status') }}</th>
                    <th>{{ __('portal.invoices.fields.pdf') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($invoices as $invoice)
                        <tr>
                            <td><strong>{{ $invoice->invoice_no }}</strong></td>
                            <td>{{ __('portal.invoices.types.'.$invoice->invoice_type) }}</td>
                            <td>{{ $invoice->period_from && $invoice->period_to ? $invoice->period_from->format('Y-m-d').' – '.$invoice->period_to->format('Y-m-d') : '—' }}</td>
                            <td>{{ $invoice->issued_at?->format('Y-m-d') ?? '—' }}</td>
                            <td>{{ $invoice->due_at?->format('Y-m-d') ?? '—' }}</td>
                            <td class="num">{{ \App\Support\Money::cents($invoice->subtotal_cents)->format() }}</td>
                            <td class="num">{{ \App\Support\Money::cents($invoice->gst_cents)->format() }}</td>
                            <td class="num">{{ \App\Support\Money::cents($invoice->total_cents)->format() }}</td>
                            <td class="num">{{ \App\Support\Money::cents($invoice->paid_amount_cents)->format() }}</td>
                            <td>
                                <span class="badge" data-tone="{{ $invoice->status === 'paid' ? 'ok' : ($invoice->is_overdue ? 'danger' : 'warn') }}">{{ __('portal.invoices.statuses.'.$invoice->status) }}</span>
                                @if ($invoice->is_overdue && $invoice->status !== 'paid')<small class="text-muted">{{ __('portal.invoices.overdue') }}</small>@endif
                            </td>
                            <td>
                                @if ($invoice->pdf_document_id)
                                    <a href="{{ route('portal.invoices.download', $invoice) }}">{{ __('portal.invoices.download') }}</a>
                                @else
                                    <span class="text-muted">{{ __('portal.not_provided') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $invoices->links() }}
    @endif
@endsection
