@extends('layouts.app')

@section('title', __('transport.runs.title'))

@section('content')
    <h1>{{ __('transport.runs.title') }}</h1>
    <p><a role="button" href="{{ route('transport.runs.create') }}">{{ __('transport.runs.create') }}</a></p>

    @if ($runs->isEmpty())
        <p>{{ __('transport.runs.empty') }}</p>
    @else
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('transport.runs.number') }}</th>
                    <th>{{ __('transport.runs.date') }}</th>
                    <th>{{ __('transport.runs.driver') }}</th>
                    <th>{{ __('transport.runs.vehicle') }}</th>
                    <th>{{ __('transport.runs.status') }}</th>
                    <th class="num">{{ __('transport.runs.stop_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td><a href="{{ route('transport.runs.show', $run) }}">{{ $run->run_no }}</a></td>
                        <td>{{ $run->run_date->format('Y-m-d') }}</td>
                        <td>{{ $run->driver->name }}</td>
                        <td>{{ $run->vehicle }}</td>
                        <td>{!! \App\Support\Ui\StatusBadge::render('transport.run_statuses.', $run->status) !!}</td>
                        <td class="num">{{ $run->stops_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $runs->links() }}
    @endif
@endsection
