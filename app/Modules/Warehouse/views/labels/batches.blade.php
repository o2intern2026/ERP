@extends('layouts.app')

@section('title', __('warehouse.labels.batches_title'))

@section('content')
    <h1>{{ $title }}</h1>
    {{-- Audit 2026-09-22 CRAWL-01 (CR #141): more than :size labels are printed as numbered batches — one PDF of 80+ HTML barcodes exhausted PHP's memory. --}}
    <p>{{ __('warehouse.labels.batches_hint', ['total' => $total, 'size' => $size, 'batches' => $batches]) }}</p>
    <ul id="label-batches">
        @foreach ($links as $link)
            <li><a href="{{ $link['url'] }}" target="_blank" role="button" class="secondary outline" style="width:auto;margin:.2rem 0">{{ __('warehouse.labels.batch_link', ['n' => $link['n'], 'from' => $link['from'], 'to' => $link['to']]) }}</a></li>
        @endforeach
    </ul>
    <p><a href="{{ url()->previous() }}">← {{ __('platform.common.back') }}</a></p>
@endsection
