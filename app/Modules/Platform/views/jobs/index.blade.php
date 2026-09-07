@extends('layouts.app')

@section('title', __('platform.jobs.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('platform.jobs.title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('platform.jobs.create') }}">{{ __('platform.jobs.create') }}</a></p>
    </header>

    <form method="get" class="grid">
        <select name="status" aria-label="{{ __('platform.jobs.filter_status') }}">
            <option value="">{{ __('platform.jobs.all') }} — {{ __('platform.jobs.operational_status') }}</option>
            @foreach ($statuses as $s)
                <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('platform.jobs.statuses.'.$s) }}</option>
            @endforeach
        </select>
        @if ($clients->count() > 1)
            <select name="client_id" aria-label="{{ __('platform.jobs.client') }}">
                <option value="">{{ __('platform.jobs.all') }} — {{ __('platform.jobs.client') }}</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        @endif
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>

    @if ($jobs->isEmpty())
        <p class="text-muted">{{ __('platform.jobs.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead>
                    <tr>
                        <th>{{ __('platform.jobs.job_no') }}</th>
                        <th>{{ __('platform.jobs.client') }}</th>
                        <th>{{ __('platform.jobs.type') }}</th>
                        <th>{{ __('platform.jobs.operational_status') }}</th>
                        <th>{{ __('platform.jobs.revenue_status') }}</th>
                        <th>{{ __('platform.jobs.reference') }}</th>
                        <th>{{ __('platform.jobs.created_at') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job)
                        <tr>
                            <td><a href="{{ route('platform.jobs.show', $job) }}">{{ $job->job_no }}</a></td>
                            <td>{{ $job->client->name }}</td>
                            <td>{{ __('platform.jobs.types.'.$job->job_type) }}</td>
                            <td><span class="badge" data-tone="{{ $job->operational_status === 'completed' ? 'ok' : ($job->operational_status === 'cancelled' ? 'muted' : 'warn') }}">{{ __('platform.jobs.statuses.'.$job->operational_status) }}</span></td>
                            <td>{{ __('platform.jobs.revenue_statuses.'.$job->revenue_status) }}</td>
                            <td>{{ $job->reference }}</td>
                            <td>{{ $job->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $jobs->links() }}
    @endif
@endsection
