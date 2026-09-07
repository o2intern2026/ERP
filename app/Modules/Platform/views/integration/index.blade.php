@extends('layouts.app')

@section('title', __('platform.integration.title'))

@section('content')
    <h1>{{ __('platform.integration.title') }}</h1>
    <p>{{ __('platform.integration.counts', $counts->all()) }}</p>
    <form method="get" class="grid">
        <select name="status" aria-label="{{ __('platform.integration.status') }}">
            <option value="">{{ __('platform.jobs.all') }}</option>
            @foreach ($counts->keys() as $s)
                <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('platform.integration.statuses.'.$s) }}</option>
            @endforeach
        </select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($events->isEmpty())
        <p class="text-muted">{{ __('platform.integration.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>{{ __('platform.integration.event') }}</th>
                        <th>Job</th>
                        <th>{{ __('platform.integration.status') }}</th>
                        <th>{{ __('platform.integration.attempts') }}</th>
                        <th>{{ __('platform.integration.available_at') }}</th>
                        <th>{{ __('platform.integration.last_error') }}</th>
                        <th>{{ __('platform.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($events as $e)
                        <tr>
                            <td>{{ $e->id }}</td>
                            <td><code>{{ $e->event_name }}</code><br><small class="text-muted">{{ $e->event_id }}</small></td>
                            <td>{{ $e->job_id ?? '—' }}</td>
                            <td><span class="badge" data-tone="{{ ['published' => 'ok', 'pending' => 'muted', 'failed' => 'warn', 'dead' => 'danger'][$e->status] }}">{{ __('platform.integration.statuses.'.$e->status) }}</span></td>
                            <td class="num">{{ $e->attempts }}</td>
                            <td>{{ $e->available_at?->format('m-d H:i') }}</td>
                            <td><small>{{ \Illuminate\Support\Str::limit($e->last_error, 120) }}</small></td>
                            <td>
                                @if (in_array($e->status, ['failed', 'dead'], true))
                                    <form method="post" action="{{ route('platform.integration.retry', $e) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('platform.integration.retry') }}</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $events->links() }}
    @endif
@endsection
