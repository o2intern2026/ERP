@extends('layouts.app')

@section('title', __('billing.charges.manual_title'))

@section('content')
    <h1>{{ __('billing.charges.manual_title') }}</h1>
    <p class="text-muted"><small>{{ __('billing.charges.manual_hint') }}</small></p>
    <form method="post" action="{{ route('billing.charges.manual.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('billing.charges.job') }}<select name="job_id" required><option value="">—</option>@foreach ($jobs as $j)<option value="{{ $j->id }}" @selected(old('job_id') == $j->id)>{{ $j->job_no }} · {{ $j->client->name }}</option>@endforeach</select></label>
            <label>{{ __('billing.charges.code') }}<select name="charge_code" required>@foreach ($codes as $code)<option value="{{ $code->code }}" @selected(old('charge_code') === $code->code)>{{ $code->code }} · {{ $code->customer_description }}</option>@endforeach</select></label>
        </div>
        <div class="grid">
            <label>{{ __('billing.charges.qty') }}<input type="number" step="0.001" min="0.001" name="qty" value="{{ old('qty', 1) }}" required></label>
            <label>{{ __('billing.charges.manual_amount') }}<input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}"></label>
            <label>{{ __('billing.charges.manual_reason') }}<input type="text" name="reason" value="{{ old('reason') }}" required></label>
        </div>
        <button type="submit">{{ __('billing.charges.manual_add') }}</button>
        <a href="{{ route('billing.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
