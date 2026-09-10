@extends('layouts.app')

@section('title', __('platform.documents.title'))

@section('content')
    <h1>{{ __('platform.documents.title') }}</h1>
    {{-- Audit 2026-09-10: the form reopens with what was typed after a validation error; 客户可见 needs a 客户 (or a Job to take it from). --}}
    <details{{ $errors->any() ? ' open' : '' }}>
        <summary role="button" class="secondary outline">{{ __('platform.documents.upload') }}</summary>
        <form method="post" action="{{ route('platform.documents.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="grid">
                <label>{{ __('platform.documents.file') }}<input type="file" name="file" required></label>
                <label>{{ __('platform.documents.type') }}<select name="type">@foreach ($types as $t)<option value="{{ $t }}" @selected(old('type') === $t)>{{ __('platform.documents.types.'.$t) }}</option>@endforeach</select></label>
                <label>{{ __('platform.documents.related_type') }}<select name="related_type">@foreach ($relatedTypes as $t)<option value="{{ $t }}" @selected(old('related_type') === $t)>{{ __('platform.documents.related_types.'.$t) }}</option>@endforeach</select></label>
                <label>{{ __('platform.documents.related_id') }}<input type="number" name="related_id" min="1" value="{{ old('related_id') }}" required></label>
            </div>
            <div class="grid">
                <label>{{ __('platform.documents.client') }}<select name="client_id" @error('client_id') aria-invalid="true" @enderror><option value="">—</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id') === $c->id)>{{ $c->name }}</option>@endforeach</select><small>{{ __('platform.documents.client_hint') }}</small></label>
                <label>{{ __('platform.documents.job') }} ID<input type="number" name="job_id" min="1" value="{{ old('job_id') }}"></label>
                <label><input type="hidden" name="client_visible" value="0"><input type="checkbox" name="client_visible" value="1" @checked(old('client_visible'))> {{ __('platform.documents.visible') }}</label>
            </div>
            <button type="submit">{{ __('platform.documents.upload') }}</button>
        </form>
    </details>
    <form method="get" class="grid">
        <select name="type"><option value="">{{ __('platform.documents.type') }}: {{ __('platform.jobs.all') }}</option>@foreach ($types as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ __('platform.documents.types.'.$t) }}</option>@endforeach</select>
        <select name="client_id"><option value="">{{ __('platform.documents.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <select name="related_type"><option value="">{{ __('platform.documents.related_type') }}: {{ __('platform.jobs.all') }}</option>@foreach ($relatedTypes as $t)<option value="{{ $t }}" @selected(($filters['related_type'] ?? '') === $t)>{{ __('platform.documents.related_types.'.$t) }}</option>@endforeach</select>
        <input type="number" name="related_id" placeholder="{{ __('platform.documents.related_id') }}" value="{{ $filters['related_id'] ?? '' }}">
        <select name="visible"><option value="">{{ __('platform.documents.visible') }}: {{ __('platform.jobs.all') }}</option><option value="1" @selected(($filters['visible'] ?? '') === '1')>{{ __('platform.documents.visible') }}</option><option value="0" @selected(($filters['visible'] ?? '') === '0')>{{ __('platform.documents.hidden') }}</option></select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($documents->isEmpty())
        <p class="text-muted">{{ __('platform.documents.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('platform.documents.type') }}</th><th>{{ __('platform.documents.file') }}</th><th>{{ __('platform.documents.related') }}</th><th>{{ __('platform.documents.client') }}</th><th>{{ __('platform.documents.job') }}</th><th>{{ __('platform.documents.visible') }}</th><th>{{ __('platform.documents.uploaded_at') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($documents as $d)
                <tr>
                    <td>{{ $d->id }}</td>
                    <td>{{ __('platform.documents.types.'.$d->type) }}</td>
                    <td><a href="{{ route('platform.documents.download', $d) }}">{{ $d->original_name ?? basename($d->storage_path) }}</a> <small class="text-muted">{{ $d->size_bytes ? number_format($d->size_bytes / 1024).' KB' : '' }}</small></td>
                    <td>{{ $d->related_type }} #{{ $d->related_id }}</td>
                    <td>{{ $d->client?->name ?? '—' }}</td>
                    <td>{{ $d->job?->job_no ?? '—' }}</td>
                    <td><span class="badge" data-tone="{{ $d->client_visible ? 'ok' : 'muted' }}">{{ $d->client_visible ? __('platform.documents.visible') : __('platform.documents.hidden') }}</span></td>
                    <td>{{ $d->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        @if (! $d->client_visible && $d->client_id === null)
                            <small class="text-muted">{{ __('platform.documents.no_client') }}</small>
                        @else
                            <form method="post" action="{{ route('platform.documents.visibility', $d) }}" class="inline">@csrf<input type="hidden" name="client_visible" value="{{ $d->client_visible ? 0 : 1 }}"><button type="submit" class="secondary outline">{{ $d->client_visible ? __('platform.documents.make_hidden') : __('platform.documents.make_visible') }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $documents->links() }}
    @endif
@endsection
