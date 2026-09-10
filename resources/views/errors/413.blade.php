@extends('layouts.app')

@section('title', __('platform.errors.too_large.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'too_large', 'backLabel' => __('platform.errors.retry')])
@endsection
