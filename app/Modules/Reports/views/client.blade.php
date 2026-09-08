@extends('layouts.app')

@section('title', $portal ? __('portal.reports.title') : __('reports.client.title'))

@section('content')
    <h1>{{ $portal ? __('portal.reports.title') : __('reports.client.title') }}@if ($client) · {{ $client->name }}@endif</h1>
    <p class="text-muted"><small>{{ $portal ? __('portal.reports.hint') : __('reports.client.hint') }}</small></p>

    @include('reports::partials.filters', ['action' => $filterAction, 'period' => $period, 'clients' => $clients, 'client' => $client])

    @if ($report === null)
        <p class="text-muted">{{ __('reports.client.pick_client') }}</p>
    @else
        <p><strong>{{ __('reports.period') }}:</strong> {{ $period->label() }}</p>
        @foreach ($tables as $table)
            <section>
                <div class="grid">
                    <h2>{{ __('reports.tables.'.$table) }}</h2>
                    <p style="text-align:right"><a href="{{ $exportUrl($table) }}">{{ __('reports.actions.export_csv') }}</a></p>
                </div>
                <p class="text-muted"><small>{{ __('reports.descriptions.'.$table) }}</small></p>
                @include('reports::partials.table', ['columns' => $columns[$table], 'rows' => $report[$table], 'totals' => $totals[$table]])
            </section>
        @endforeach
    @endif
@endsection
