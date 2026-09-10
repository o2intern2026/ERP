{{-- A7b: shared by the staff order page and the portal. $estimate (OrderEstimateService::current or null), $canEstimate (bool), $estimateRoute (url), $staff (bool). Customer prices only. --}}
<article>
    <header><strong>{{ __('orders.estimate.title') }}</strong>
        @if ($estimate)
            <small class="text-muted">· {{ $estimate['quote']->quote_no }} · {{ __('orders.estimate.stages.'.$estimate['quote']->stage) }} · {!! \App\Support\Ui\StatusBadge::render('orders.estimate.statuses.', $estimate['quote']->status) !!} · {{ $estimate['quote']->created_at->format('Y-m-d H:i') }}</small>
            @if ($staff && auth()->user()->hasAnyRole(['admin', 'finance', 'customer_service']))
                · <a href="{{ route('billing.quotes.show', $estimate['quote']) }}"><small>{{ __('orders.estimate.open_quote') }}</small></a>
            @endif
        @endif
    </header>
    <p class="text-muted"><small>{{ __('orders.estimate.hint') }}</small></p>

    @if ($estimate)
        <div class="overflow-auto">
            <table class="dense">
                <thead><tr><th>{{ __('orders.estimate.fields.item') }}</th><th class="num">{{ __('orders.estimate.fields.qty') }}</th><th>{{ __('orders.estimate.fields.uom') }}</th><th class="num">{{ __('orders.estimate.fields.amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($estimate['lines'] as $line)
                        <tr>
                            <td>{{ $line['description'] }} <small class="text-muted">{{ $line['charge_code'] }}</small>
                                @if ($line['weight_assumed'])<br><small class="text-muted">{{ __('orders.estimate.weight_assumed') }}</small>@endif
                            </td>
                            <td class="num">{{ $line['qty'] }}</td>
                            <td>{{ __('orders.estimate.uoms.'.$line['uom']) }}</td>
                            <td class="num">
                                @if ($line['flag'] === null)
                                    {{ \App\Support\Money::cents($line['amount_cents'])->format() }}
                                @else
                                    <span class="badge" data-tone="warn">{{ __('orders.estimate.flags.'.$line['flag']) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="3"><strong>{{ __('orders.estimate.fields.freight') }}</strong>
                            @if ($estimate['freight'])
                                <br><small class="text-muted">{{ $estimate['freight']['label'] }}
                                    @if ($estimate['freight']['is_recommended']) · {{ __('orders.estimate.freight_flags.recommended') }}@endif
                                    @if ($estimate['freight']['is_cheapest']) · {{ __('orders.estimate.freight_flags.cheapest') }}@endif
                                    @if ($estimate['freight']['is_fastest']) · {{ __('orders.estimate.freight_flags.fastest') }}@endif
                                    @if ($estimate['freight']['eta_days'] !== null) · {{ __('orders.estimate.eta_days', ['days' => $estimate['freight']['eta_days']]) }}@endif
                                    · {{ __('orders.estimate.stages.'.$estimate['freight']['quote_stage']) }}
                                </small>
                            @endif
                        </td>
                        <td class="num">
                            @if ($estimate['freight'])
                                {{ \App\Support\Money::cents($estimate['freight']['customer_price_cents'])->format() }}
                            @else
                                <span class="badge" data-tone="muted">{{ __('orders.estimate.freight_pending') }}</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr><th colspan="3">{{ __('orders.estimate.fields.subtotal') }}</th><th class="num">{{ $estimate['unpriced'] > 0 && $estimate['subtotal_cents'] === 0 ? __('orders.estimate.flags.missing') : \App\Support\Money::cents($estimate['subtotal_cents'])->format() }}</th></tr>
                    <tr><th colspan="3">{{ __('orders.estimate.fields.total') }}
                        @if ($estimate['unpriced'] > 0)<br><small class="text-muted">{{ __('orders.estimate.unpriced_note', ['count' => $estimate['unpriced']]) }}</small>@endif
                        @if (! $estimate['freight'])<br><small class="text-muted">{{ __('orders.estimate.freight_excluded') }}</small>@endif
                    </th><th class="num">{{ $estimate['total_cents'] === null ? __('orders.estimate.flags.missing') : \App\Support\Money::cents($estimate['total_cents'])->format() }}</th></tr>
                    <tr><th colspan="3">{{ __('orders.estimate.fields.gst') }}</th><th class="num">{{ $estimate['total_cents'] === null ? '—' : \App\Support\Money::cents($estimate['gst_cents'])->format() }}</th></tr>
                    <tr><th colspan="3">{{ __('orders.estimate.fields.total_inc_gst') }}</th><th class="num">{{ $estimate['total_inc_gst_cents'] === null ? __('orders.estimate.flags.missing') : \App\Support\Money::cents($estimate['total_inc_gst_cents'])->format() }}</th></tr>
                </tfoot>
            </table>
        </div>
    @else
        <p class="text-muted">{{ __('orders.estimate.none') }}</p>
    @endif

    @if ($canEstimate)
        <form method="post" action="{{ $estimateRoute }}" class="inline">
            @csrf
            <button type="submit" class="secondary">{{ __($estimate ? 'orders.estimate.actions.refresh' : 'orders.estimate.actions.create') }}</button>
        </form>
    @endif
</article>
