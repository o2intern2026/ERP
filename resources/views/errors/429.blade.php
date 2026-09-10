@extends('layouts.app')

@section('title', __('platform.errors.too_many_requests.title'))

@section('content')
    @php($seconds = isset($exception) && method_exists($exception, 'getHeaders') ? (int) ($exception->getHeaders()['Retry-After'] ?? 0) : 0)
    @include('errors.partials.card', ['key' => 'too_many_requests', 'backLabel' => __('platform.errors.retry'), 'extra' => $seconds > 0 ? __('platform.errors.too_many_requests.wait', ['seconds' => $seconds]) : null])
@endsection
