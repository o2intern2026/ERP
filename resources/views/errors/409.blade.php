@extends('layouts.app')

@section('title', __('platform.errors.conflict.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'conflict', 'backLabel' => __('platform.errors.retry')])
@endsection
