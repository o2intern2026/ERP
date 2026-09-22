@extends('layouts.app')

@section('title', __('billing.charges.review_queue'))

@section('content')
    <h1>{{ __('billing.charges.review_queue') }}</h1>
    <p class="text-muted"><small>{{ __('billing.charges.review_hint') }}</small></p>
    @if ($client)
        <p><small>{{ __('billing.charges.review_client_only', ['name' => $client->name]) }} <a href="{{ route('billing.charges.review') }}">{{ __('billing.charges.review_all') }}</a></small></p>
    @endif
    @if ($charges->isEmpty())
        <p class="text-muted">{{ __('billing.charges.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('billing.charges.client') }}</th><th>{{ __('billing.charges.job') }}</th><th>{{ __('billing.charges.code') }}</th><th class="num">{{ __('billing.charges.qty') }}</th><th>{{ __('billing.charges.snapshot') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($charges as $c)
                @php($snapshot = $c->calculation_snapshot_json ?? [])
                @php($missingRate = \App\Modules\Billing\Services\ChargeEngine::isUnpricedMissingRate($c))
                @php($suggested = $missingRate && isset($snapshot['suggested_cents']) ? (int) $snapshot['suggested_cents'] : null)
                <tr>
                    <td>{{ $c->id }}</td><td>{{ $c->client->name }}</td><td>{{ $c->job->job_no }}</td>
                    <td><code>{{ $c->chargeCode->code }}</code> @if ($missingRate)<span class="badge" data-tone="danger">{{ __('billing.charges.missing_rate_badge') }}</span>@endif<br><small class="text-muted">{{ $c->chargeCode->customer_description }} · {{ $c->is_manual ? $c->manual_reason : ($c->source_type.' #'.$c->source_id) }}</small></td>
                    <td class="num">{{ rtrim(rtrim(number_format($c->qty, 3), '0'), '.') }} {{ $c->uom }}</td>
                    <td><small><code>{{ json_encode(collect($snapshot)->except(['context'])->all(), JSON_UNESCAPED_UNICODE) }}</code></small></td>
                    <td>
                        {{-- CR #132: a missing-rate row pre-fills the customer freight price the event carried (TR-DELIVERY-BASE); pricing it closes the linked exception. --}}
                        <form method="post" action="{{ route('billing.charges.review.store', $c) }}" class="inline">@csrf<input type="number" step="0.01" min="0" name="amount" value="{{ $suggested !== null ? number_format($suggested / 100, 2, '.', '') : '' }}" placeholder="{{ __('billing.charges.review_amount') }}" style="width:8rem" required><input type="text" name="note" placeholder="{{ __('billing.charges.review_note') }}" style="width:14rem" required><button type="submit">{{ __('billing.charges.review_do') }}</button></form>
                        @if ($missingRate)<br><small class="text-muted">{{ $suggested !== null ? __('billing.charges.suggested_amount', ['amount' => \App\Support\Money::cents($suggested)->format()]) : __('billing.charges.no_suggested_amount') }}</small>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $charges->links() }}
    @endif
@endsection
