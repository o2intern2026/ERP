@extends('layouts.app')

@section('title', __('transport.runs.create'))

@section('content')
    <p><a href="{{ route('transport.runs.index') }}">{{ __('transport.runs.back') }}</a></p>
    <h1>{{ __('transport.runs.create') }}</h1>

    <form method="post" action="{{ route('transport.runs.store') }}">
        @csrf
        <label>
            {{ __('transport.runs.date') }}
            <input type="date" name="run_date" value="{{ old('run_date', now()->toDateString()) }}" required>
        </label>
        <label>
            {{ __('transport.runs.driver') }}
            <select name="driver_id" required>
                <option value="">{{ __('transport.runs.choose_driver') }}</option>
                @foreach ($drivers as $driver)
                    <option value="{{ $driver->id }}" @selected((string) old('driver_id') === (string) $driver->id)>
                        {{ $driver->name }}
                    </option>
                @endforeach
            </select>
        </label>
        <label>
            {{ __('transport.runs.vehicle') }}
            <input type="text" name="vehicle" value="{{ old('vehicle') }}" maxlength="100" required>
        </label>
        <button type="submit">{{ __('transport.runs.save') }}</button>
    </form>
@endsection
