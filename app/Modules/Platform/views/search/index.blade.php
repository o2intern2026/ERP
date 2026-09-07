@extends('layouts.app')

@section('title', __('platform.search.title'))

@section('content')
    <h1>{{ __('platform.search.title') }}</h1>
    <form method="get" class="grid">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('platform.search_placeholder') }}" autofocus>
        <button type="submit">{{ __('platform.search.title') }}</button>
    </form>
    @if (mb_strlen($q) < 2)
        <p class="text-muted">{{ __('platform.search.hint') }}</p>
    @elseif ($results === [])
        <p class="text-muted">{{ __('platform.search.empty', ['q' => $q]) }}</p>
    @else
        @foreach ($results as $module => $hits)
            <h2>{{ __('platform.search.modules.'.$module) }}</h2>
            <table class="dense">
                <tbody>
                @foreach ($hits as $hit)
                    <tr><td style="width:9rem"><span class="badge" data-tone="muted">{{ __('platform.search.types.'.$hit['type']) }}</span></td><td><a href="{{ $hit['url'] }}">{{ $hit['label'] }}</a></td><td class="text-muted">{{ $hit['meta'] ?? '' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
