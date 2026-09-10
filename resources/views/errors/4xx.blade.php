@extends('layouts.app')

{{-- Fallback for every 4xx status without a dedicated page (400, 401, 402, 410 …) — Laravel picks errors::4xx before Symfony's English page. --}}
@section('title', __('platform.errors.request_failed.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'request_failed'])
@endsection
