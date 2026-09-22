@extends('layouts.app')

@section('title', __('billing.charges.manual_title'))

@section('content')
    <h1>{{ __('billing.charges.manual_title') }}</h1>
    <p class="text-muted"><small>{{ __('billing.charges.manual_hint') }}</small></p>
    {{-- Audit 2026-09-22 FIN-09 (CR #140): the Job page's 手工加费 link arrives with ?job_id=&client_id= — the Job is pre-selected and the list narrowed to that client. --}}
    @if ($client)
        <p class="text-muted"><small>{{ __('billing.charges.manual_job_filter', ['name' => $client->name]) }} · <a href="{{ route('billing.charges.manual') }}">{{ __('billing.charges.manual_all_jobs') }}</a></small></p>
    @endif
    <form method="post" action="{{ route('billing.charges.manual.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('billing.charges.job') }}<select name="job_id" required><option value="">—</option>@foreach ($jobs as $j)<option value="{{ $j->id }}" @selected((int) old('job_id', $prefillJobId ?? 0) === $j->id)>{{ $j->job_no }} · {{ $j->client->name }}</option>@endforeach</select></label>
            {{-- FIN-09: no silent default — the first option is blank so a forgotten dropdown fails validation instead of posting the alphabetically first (cartage) code. --}}
            <label>{{ __('billing.charges.code') }}<select name="charge_code" required><option value="">{{ __('billing.charges.select_code') }}</option>@foreach ($codes as $code)<option value="{{ $code->code }}" @selected(old('charge_code') === $code->code)>{{ $code->code }} · {{ $code->customer_description }}</option>@endforeach</select></label>
        </div>
        <div class="grid">
            <label>{{ __('billing.charges.charge_date') }}<x-date-field name="charge_date" :value="old('charge_date', today()->toDateString())" max="{{ today()->toDateString() }}" required /><small class="text-muted">{{ __('billing.charges.charge_date_hint') }}</small></label>
            <label>{{ __('billing.charges.qty') }}<input type="number" step="0.001" min="0.001" name="qty" value="{{ old('qty', 1) }}" required></label>
            <label>{{ __('billing.charges.manual_amount') }}<input type="number" step="0.01" min="0" name="amount" value="{{ old('amount') }}"></label>
            <label>{{ __('billing.charges.manual_reason') }}<input type="text" name="reason" value="{{ old('reason') }}" required></label>
        </div>
        <button type="submit">{{ __('billing.charges.manual_add') }}</button>
        <a href="{{ route('billing.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
