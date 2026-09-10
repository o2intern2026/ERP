@extends('layouts.app')

@section('title', __('platform.errors.server_error.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'server_error', 'backLabel' => __('platform.errors.retry')])
@endsection
