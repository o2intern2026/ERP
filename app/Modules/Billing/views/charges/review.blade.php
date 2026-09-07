@extends('layouts.app')

@section('title', __('billing.charges.review_queue'))

@section('content')
    <h1>{{ __('billing.charges.review_queue') }}</h1>
    <p class="text-muted"><small>{{ __('billing.charges.review_hint') }}</small></p>
    @if ($charges->isEmpty())
        <p class="text-muted">{{ __('billing.charges.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('billing.charges.client') }}</th><th>{{ __('billing.charges.job') }}</th><th>{{ __('billing.charges.code') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th>{{ __('billing.charges.snapshot') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($charges as $c)
                <tr>
                    <td>{{ $c->id }}</td><td>{{ $c->client->name }}</td><td>{{ $c->job->job_no }}</td><td><code>{{ $c->chargeCode->code }}</code><br><small class="text-muted">{{ $c->chargeCode->customer_description }} · {{ $c->is_manual ? $c->manual_reason : ($c->source_type.' #'.$c->source_id) }}</small></td>
                    <td class="num">{{ rtrim(rtrim(number_format($c->qty, 3), '0'), '.') }} {{ $c->uom }}</td>
                    <td><small><code>{{ json_encode(collect($c->calculation_snapshot_json)->except(['context'])->all(), JSON_UNESCAPED_UNICODE) }}</code></small></td>
                    <td><form method="post" action="{{ route('billing.charges.review.store', $c) }}" class="inline">@csrf<input type="number" step="0.01" min="0" name="amount" placeholder="{{ __('billing.charges.review_amount') }}" style="width:8rem" required><input type="text" name="note" placeholder="{{ __('billing.charges.review_note') }}" style="width:14rem" required><button type="submit">{{ __('billing.charges.review_do') }}</button></form></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $charges->links() }}
    @endif
@endsection
