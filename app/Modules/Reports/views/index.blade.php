@extends('layouts.app')

@section('title', __('reports.title'))

@section('content')
    <h1>{{ __('reports.title') }} · {{ __('reports.boss.title') }}</h1>
    <p class="text-muted"><small>{{ __('reports.boss.hint') }}</small></p>
    <p><a href="{{ route('reports.client') }}">{{ __('reports.client.nav') }} →</a></p>

    @include('reports::partials.filters', ['action' => route('reports.index'), 'period' => $period, 'clients' => collect(), 'client' => null])
    <p><strong>{{ __('reports.period') }}:</strong> {{ $period->label() }}</p>

    @foreach ($tables as $table)
        <section>
            <div class="grid">
                <h2>{{ __('reports.tables.'.$table) }}</h2>
                <p style="text-align:right"><a href="{{ $exportUrl($table) }}">{{ __('reports.actions.export_csv') }}</a></p>
            </div>
            <p class="text-muted"><small>{{ __('reports.descriptions.'.$table) }}@if (\Illuminate\Support\Facades\Lang::has('reports.boss_notes.'.$table)) {{ __('reports.boss_notes.'.$table) }}@endif</small></p>
            @include('reports::partials.table', ['columns' => $columns[$table], 'rows' => $report[$table], 'totals' => $totals[$table]])
        </section>
    @endforeach
@endsection
