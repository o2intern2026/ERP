@extends('layouts.app')

@section('title', __('platform.errors.not_found.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'not_found'])
@endsection
