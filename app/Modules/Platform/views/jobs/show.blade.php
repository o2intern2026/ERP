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
    <div class="grid">
        @foreach (['panel_asns' => ['Warehouse', 'M2'], 'panel_stock' => ['Warehouse', 'M2'], 'panel_orders' => ['Orders', 'M3'], 'panel_shipments' => ['Transport', 'M5'], 'panel_charges' => ['Billing', 'M6'], 'panel_documents' => ['Platform', 'M6']] as $key => [$module, $checkpoint])
            <article>
                <header>{{ __('platform.jobs.'.$key) }}</header>
                <p class="text-muted">{{ __('platform.jobs.panel_pending', ['module' => $module, 'checkpoint' => $checkpoint]) }}</p>
            </article>
        @endforeach
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
                <tfoot><tr><td colspan="3"><strong>{{ __('billing.job_panel.revenue') }}</strong> · <small class="text-muted">{{ __('billing.job_panel.pending_cost') }}</small></td><td class="num"><strong>{{ number_format($jobCharges->sum('amount_cents') / 100, 2) }}</strong></td><td><a href="{{ route('billing.index', ['job_no' => $job->job_no]) }}">{{ __('billing.job_panel.open') }}</a></td></tr></tfoot>
            </table>
        @endif
    @endrole

@endsection
