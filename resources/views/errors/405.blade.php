@extends('layouts.app')

@section('title', __('platform.errors.method_not_allowed.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'method_not_allowed'])
@endsection
