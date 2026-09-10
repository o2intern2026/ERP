@extends('layouts.app')

{{-- Fallback for every 5xx status without a dedicated page (502, 504 …). Never shows the exception message. --}}
@section('title', __('platform.errors.system_failed.title'))

@section('content')
    @include('errors.partials.card', ['key' => 'system_failed', 'backLabel' => __('platform.errors.retry'), 'showReason' => false])
@endsection
