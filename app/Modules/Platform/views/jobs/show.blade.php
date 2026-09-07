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
@endsection
