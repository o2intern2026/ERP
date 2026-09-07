@extends('layouts.app')

@section('title', __('platform.activity.title'))

@section('content')
    <h1>{{ __('platform.activity.title') }}</h1>
    <form method="get" class="grid">
        <select name="subject_type"><option value="">{{ __('platform.activity.subject') }}: {{ __('platform.jobs.all') }}</option>@foreach ($subjectTypes as $t)<option value="{{ $t }}" @selected(($filters['subject_type'] ?? '') === $t)>{{ class_basename($t) }}</option>@endforeach</select>
        <select name="causer_id"><option value="">{{ __('platform.activity.who') }}: {{ __('platform.jobs.all') }}</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) ($filters['causer_id'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach</select>
        <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" aria-label="{{ __('platform.activity.from') }}">
        <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" aria-label="{{ __('platform.activity.to') }}">
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($activities->isEmpty())
        <p class="text-muted">{{ __('platform.activity.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('platform.activity.time') }}</th><th>{{ __('platform.activity.who') }}</th><th>{{ __('platform.activity.what') }}</th><th>{{ __('platform.activity.subject') }}</th><th>{{ __('platform.activity.changes') }}</th></tr></thead>
            <tbody>
            @foreach ($activities as $a)
                <tr>
                    <td>{{ $a->created_at->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $a->causer?->name ?? __('platform.activity.system') }}</td>
                    <td>{{ __('platform.activity.events.'.$a->event) !== 'platform.activity.events.'.$a->event ? __('platform.activity.events.'.$a->event) : $a->description }}</td>
                    <td>{{ class_basename($a->subject_type) }} #{{ $a->subject_id }}</td>
                    <td><small>
                        @php($attrs = $a->properties['attributes'] ?? [])
                        @php($old = $a->properties['old'] ?? [])
                        @foreach ($attrs as $k => $v)
                            <code>{{ $k }}</code>: {{ is_scalar($old[$k] ?? null) || ($old[$k] ?? null) === null ? ($old[$k] ?? '∅') : json_encode($old[$k]) }} → {{ is_scalar($v) || $v === null ? ($v ?? '∅') : json_encode($v, JSON_UNESCAPED_UNICODE) }}<br>
                        @endforeach
                    </small></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $activities->links() }}
    @endif
@endsection
