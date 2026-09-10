@extends('layouts.app')

@section('title', __('platform.errors.'.(app()->isDownForMaintenance() ? 'maintenance' : 'unavailable').'.title'))

@section('content')
    @include('errors.partials.card', ['key' => app()->isDownForMaintenance() ? 'maintenance' : 'unavailable', 'backLabel' => __('platform.errors.retry')])
@endsection
