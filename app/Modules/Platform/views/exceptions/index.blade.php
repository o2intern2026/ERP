@extends('layouts.app')

@section('title', __('platform.exceptions.title'))

@section('content')
    <h1>{{ __('platform.exceptions.title') }}</h1>
    <p>{{ __('platform.exceptions.counts', ['open' => $counts['open'] ?? 0, 'in_progress' => $counts['in_progress'] ?? 0, 'resolved' => $counts['resolved'] ?? 0]) }}</p>
    <form method="get" class="grid">
        <select name="status"><option value="">{{ __('platform.exceptions.active') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('platform.exceptions.statuses.'.$s) }}</option>@endforeach</select>
        <select name="type"><option value="">{{ __('platform.exceptions.type') }}: {{ __('platform.jobs.all') }}</option>@foreach ($types as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ __('platform.exceptions.types.'.$t) }}</option>@endforeach</select>
        <select name="source_module"><option value="">{{ __('platform.exceptions.module') }}: {{ __('platform.jobs.all') }}</option>@foreach ($modules as $m)<option value="{{ $m }}" @selected(($filters['source_module'] ?? '') === $m)>{{ __('platform.exceptions.modules.'.$m) }}</option>@endforeach</select>
        <select name="client_id"><option value="">{{ __('platform.exceptions.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <select name="owner"><option value="">{{ __('platform.exceptions.owner') }}: {{ __('platform.jobs.all') }}</option><option value="me" @selected(($filters['owner'] ?? '') === 'me')>{{ __('platform.exceptions.mine') }}</option><option value="unassigned" @selected(($filters['owner'] ?? '') === 'unassigned')>{{ __('platform.exceptions.unassigned') }}</option></select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($exceptions->isEmpty())
        <p class="text-muted">{{ __('platform.exceptions.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('platform.exceptions.type') }}</th><th>{{ __('platform.exceptions.module') }}</th><th>{{ __('platform.exceptions.client') }}</th><th>{{ __('platform.exceptions.job') }}</th><th>{{ __('platform.exceptions.message') }}</th><th>{{ __('platform.exceptions.owner') }}</th><th>{{ __('platform.exceptions.status') }}</th><th>{{ __('platform.exceptions.created') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($exceptions as $e)
                <tr>
                    <td>{{ $e->id }}</td>
                    <td>{{ __('platform.exceptions.types.'.$e->type) }}@if ($e->hold_type)<br><small class="text-muted">{{ __('platform.exceptions.hold_types.'.$e->hold_type) }}</small>@endif</td>
                    <td>{{ __('platform.exceptions.modules.'.$e->source_module) }}</td>
                    <td>{{ $e->client?->name ?? '—' }}</td>
                    <td>@if ($e->job)<a href="{{ route('platform.jobs.show', $e->job) }}">{{ $e->job->job_no }}</a>@endif</td>
                    <td>{{ $e->message }} @if ($url = $sourceUrl($e))<br><a href="{{ $url }}"><small>{{ __('platform.exceptions.source') }}: {{ \Illuminate\Support\Facades\Lang::has('platform.source_types.'.$e->source_type) ? __('platform.source_types.'.$e->source_type) : $e->source_type }} #{{ $e->source_id }}</small></a>@endif</td>
                    <td>{{ $e->owner?->name ?? '—' }}</td>
                    <td><span class="badge" data-tone="{{ ['open' => 'danger', 'in_progress' => 'warn', 'resolved' => 'ok'][$e->status] }}">{{ __('platform.exceptions.statuses.'.$e->status) }}</span></td>
                    <td>{{ $e->created_at->format('m-d H:i') }}</td>
                    <td>
                        @if ($e->status !== 'resolved')
                            <form method="post" action="{{ route('platform.exceptions.assign', $e) }}" class="inline">@csrf<select name="owner_id" style="width:9rem;padding:.2rem;margin:0"><option value="">{{ __('platform.exceptions.take') }}</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected($e->owner_id === $u->id)>{{ $u->name }}</option>@endforeach</select><button type="submit" class="secondary outline">{{ __('platform.exceptions.assign_to') }}</button></form>
                            @if ($e->status === 'open')<form method="post" action="{{ route('platform.exceptions.start', $e) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('platform.exceptions.start') }}</button></form>@endif
                            <form method="post" action="{{ route('platform.exceptions.resolve', $e) }}" class="inline">@csrf<input type="text" name="note" placeholder="{{ __('platform.exceptions.note') }}" style="width:14rem" @required($e->isHold())><button type="submit">{{ __('platform.exceptions.resolve') }}</button></form>
                        @else
                            <small class="text-muted">{{ $e->resolved_at?->format('m-d H:i') }} · {{ $e->release_reason }}</small>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $exceptions->links() }}
    @endif
@endsection
