@extends('layouts.app')

@section('title', __('platform.jobs.create'))

@section('content')
    <h1>{{ __('platform.jobs.create') }}</h1>
    <form method="post" action="{{ route('platform.jobs.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('platform.jobs.client') }}
                <select name="client_id" required>
                    <option value="">—</option>
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->code }} · {{ $c->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>{{ __('platform.jobs.type') }}
                <select name="job_type" required>
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected(old('job_type', 'container') === $t)>{{ __('platform.jobs.types.'.$t) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <label>{{ __('platform.jobs.reference') }}<input type="text" name="reference" value="{{ old('reference') }}" maxlength="255"></label>
        <label>{{ __('platform.jobs.notes') }}<textarea name="notes" rows="3">{{ old('notes') }}</textarea></label>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('platform.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
