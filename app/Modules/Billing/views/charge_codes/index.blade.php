@extends('layouts.app')

@section('title', __('billing.charge_codes.title'))

@section('content')
    <h1>{{ __('billing.charge_codes.title') }}</h1>
    <p class="text-muted"><small>{{ __('billing.charge_codes.hint') }}</small></p>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('billing.charge_codes.code') }}</th><th>{{ __('billing.charge_codes.category') }}</th><th>{{ __('billing.charge_codes.uom') }}</th><th>{{ __('billing.charge_codes.description') }}</th><th>{{ __('billing.charge_codes.tax') }}</th><th>{{ __('billing.charge_codes.rules') }}</th></tr></thead>
        <tbody>
        @foreach ($codes as $code)
            <tr><td><code>{{ $code->code }}</code></td><td>{{ __('billing.categories.'.$code->category) }}</td><td>{{ $code->default_uom }}</td><td>{{ $code->customer_description }}<br><small class="text-muted">{{ $code->internal_description }}</small></td><td>{{ $code->tax_treatment }}</td>
                <td><small>@foreach ($code->rules as $r)<code>{{ $r->trigger_event }}</code> → {{ $r->quantity_source }} @if ($r->condition) {{ json_encode($r->condition, JSON_UNESCAPED_UNICODE) }}@endif<br>@endforeach</small></td></tr>
        @endforeach
        </tbody>
    </table></div>
@endsection
