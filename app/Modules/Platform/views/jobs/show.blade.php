@extends('layouts.app')

@section('title', $job->job_no)

@section('content')
    <p><a href="{{ route('platform.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $job->job_no }} <small class="text-muted">{{ __('platform.jobs.types.'.$job->job_type) }}</small></h1>
        <p>{{ $job->client->name }} · {{ $job->reference }}</p>
    </header>

    <div class="grid">
        <article>
            <header>{{ __('platform.jobs.operational_status') }}</header>
            <span class="badge" data-tone="warn">{{ __('platform.jobs.statuses.'.$summary['operational_status']) }}</span>
        </article>
        <article>
            <header>{{ __('platform.jobs.revenue_status') }}</header>
            {{ __('platform.jobs.revenue_statuses.'.$summary['revenue_status']) }}
            <p>{{ __('platform.jobs.estimated_revenue') }}: {{ \App\Support\Money::cents($summary['estimated_revenue_cents']) }} · {{ __('platform.jobs.actual_revenue') }}: {{ \App\Support\Money::cents($summary['actual_revenue_cents']) }}</p>
        </article>
        @if (array_key_exists('margin_cents', $summary))
            <article>
                <header>{{ __('platform.jobs.cost_status') }}</header>
                {{ __('platform.jobs.cost_statuses.'.$summary['cost_status']) }}
                <p>{{ __('platform.jobs.estimated_cost') }}: {{ \App\Support\Money::cents($summary['estimated_cost_cents']) }} · {{ __('platform.jobs.actual_cost') }}: {{ \App\Support\Money::cents($summary['actual_cost_cents']) }}</p>
                <p><strong>{{ __('platform.jobs.margin') }}: {{ \App\Support\Money::cents($summary['margin_cents']) }}</strong> @if ($summary['margin_is_estimate']) <small class="text-muted">{{ __('platform.jobs.margin_estimate') }}</small> @endif</p>
            </article>
        @endif
    </div>

    <h2>{{ __('platform.jobs.panels') }}</h2>
    {{-- Audit 2026-09-10: cross-module links only for roles the target route group admits (Warehouse read group: routes.php); others see plain text. --}}
    @php($canOpenWarehouse = auth()->user()->hasAnyRole(['admin', 'warehouse_supervisor', 'warehouse_operator', 'dispatcher', 'customer_service', 'finance']))
    <div class="grid">
        <article>
            <header>{{ __('platform.jobs.panel_asns') }} <small class="text-muted">{{ $panels['asns']->count() }}</small></header>
            @forelse ($panels['asns'] as $a)
                <p>@if ($canOpenWarehouse)<a href="{{ route('warehouse.asns.show', $a->id) }}">{{ $a->asn_no }}</a>@else{{ $a->asn_no }}@endif · {{ __('warehouse.asn_statuses.'.$a->status) }} · {{ $a->lines_count }} {{ __('platform.jobs.lines') }}</p>
            @empty
                <p class="text-muted">{{ __('platform.jobs.none') }}</p>
            @endforelse
        </article>
        <article>
            <header>{{ __('platform.jobs.panel_stock') }}</header>
            <p>{{ __('platform.jobs.stock_units') }}: {{ (int) $panels['stock']->units }} · {{ __('platform.jobs.on_hand') }}: {{ (int) $panels['stock']->on_hand }} · {{ __('platform.jobs.reserved') }}: {{ (int) $panels['stock']->reserved }}</p>
            @if ($canOpenWarehouse)<p><a href="{{ route('warehouse.index') }}">{{ __('platform.jobs.open_stock') }}</a></p>@endif
        </article>
        <article>
            <header>{{ __('platform.jobs.panel_orders') }} <small class="text-muted">{{ $panels['orders']->count() }}</small></header>
            @forelse ($panels['orders'] as $o)
                <p><a href="{{ route('orders.show', $o->id) }}">{{ $o->order_no }}</a> · {{ __('orders.statuses.'.$o->operational_status) }} · <small class="text-muted">{{ $o->billing_status }}</small></p>
            @empty
                <p class="text-muted">{{ __('platform.jobs.none') }}</p>
            @endforelse
        </article>
        <article>
            <header>{{ __('platform.jobs.panel_shipments') }} <small class="text-muted">{{ $panels['shipments']->count() }}</small></header>
            @forelse ($panels['shipments'] as $s)
                <p>@if (Route::has('transport.shipments.show'))<a href="{{ route('transport.shipments.show', $s->id) }}">{{ $s->shipment_no }}</a>@else{{ $s->shipment_no }}@endif · {{ $s->status }} @if ($s->tracking_number)· {{ __('platform.jobs.tracking') }} {{ $s->tracking_number }}@endif</p>
            @empty
                <p class="text-muted">{{ __('platform.jobs.none') }}</p>
            @endforelse
        </article>
        <article>
            <header>{{ __('platform.jobs.panel_documents') }} <small class="text-muted">{{ $panels['documents']->count() }}</small></header>
            @forelse ($panels['documents'] as $d)
                <p>{{ $d->type }} · {{ $d->original_name ?? basename((string) $d->storage_path) }}</p>
            @empty
                <p class="text-muted">{{ __('platform.jobs.none') }}</p>
            @endforelse
            <p><a href="{{ route('platform.documents.index') }}">{{ __('platform.jobs.open_documents') }}</a></p>
        </article>
        <article>
            <header>{{ __('platform.jobs.panel_invoices') }} <small class="text-muted">{{ $panels['invoices']->count() }}</small></header>
            @forelse ($panels['invoices'] as $inv)
                <p>{{ $inv->invoice_no ?? __('platform.jobs.draft') }} · {{ $inv->invoice_type }} · {{ $inv->status }} · {{ \App\Support\Money::cents($inv->total_cents ?? 0) }}</p>
            @empty
                <p class="text-muted">{{ __('platform.jobs.none') }}</p>
            @endforelse
        </article>
    </div>

    @if ($job->notes)
        <h3>{{ __('platform.jobs.notes') }}</h3>
        <p>{{ $job->notes }}</p>
    @endif
    <p class="text-muted">{{ __('platform.jobs.created_at') }}: {{ $job->created_at->format('Y-m-d H:i') }} · {{ $job->creator?->name }}</p>
    @role('admin|finance|customer_service|dispatcher')
        @php($jobCharges = \App\Modules\Billing\Models\Charge::query()->with('chargeCode')->where('job_id', $job->id)->where('status', '!=', 'reversed')->orderBy('id')->get())
        @if ($jobCharges->isNotEmpty())
            <h2>{{ __('billing.job_panel.title') }}</h2>
            <table class="dense">
                <thead><tr><th>{{ __('billing.charges.date') }}</th><th>{{ __('billing.charges.code') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th class="num">{{ __('billing.charges.amount') }}</th><th>{{ __('billing.charges.status') }}</th></tr></thead>
                <tbody>
                @foreach ($jobCharges as $c)
                    <tr><td>{{ $c->charge_date->format('Y-m-d') }}</td><td><code>{{ $c->chargeCode->code }}</code> <small class="text-muted">{{ $c->chargeCode->customer_description }}</small></td><td class="num">{{ rtrim(rtrim(number_format($c->qty, 3), '0'), '.') }}</td><td class="num">{{ number_format($c->amount_cents / 100, 2) }}</td><td>{{ __('billing.charge_statuses.'.$c->status) }}</td></tr>
                @endforeach
                </tbody>
                <tfoot><tr><td colspan="3"><strong>{{ __('billing.job_panel.revenue') }}</strong> · <small class="text-muted">{{ __('billing.job_panel.pending_cost') }}</small></td><td class="num"><strong>{{ number_format($jobCharges->sum('amount_cents') / 100, 2) }}</strong></td><td>@role('admin|finance')<a href="{{ route('billing.index', ['job_no' => $job->job_no]) }}">{{ __('billing.job_panel.open') }}</a>@endrole</td></tr></tfoot>
            </table>
        @endif
    @endrole

@endsection
