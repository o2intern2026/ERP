@extends('layouts.app')

@section('title', __('platform.errors.page_expired.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'page_expired', 'backLabel' => __('platform.errors.retry')])
@endsection
