@extends('layouts.app')

@section('title', __('orders.fulfilments.title', ['order_no' => $order->order_no]))

@section('content')
    <p><a href="{{ route('orders.show', $order) }}">← {{ __('orders.fulfilments.actions.back') }}</a></p>
    <h1>{{ __('orders.fulfilments.title', ['order_no' => $order->order_no]) }}</h1>
    <p>{{ $order->client->name }} · {{ __('orders.fields.fulfilment_status') }}: <strong>{{ __('orders.statuses.fulfilment.'.$order->fulfilment_status) }}</strong></p>

    <h2>{{ __('orders.fulfilments.availability_title') }}</h2>
    @include('orders::fulfilments.partials.availability')

    <h2>{{ __('orders.fulfilments.batches_title') }}</h2>
    @include('orders::fulfilments.partials.batches')
@endsection
