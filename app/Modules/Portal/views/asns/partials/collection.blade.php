{{-- 到仓方式 我方上门提货 in the portal (CHANGE_REQUESTS #124): pickup address, ready date, progress, the confirmed plan and the CLIENT price.
     Nothing here reads carrier cost or margin (asns.collection_plan / collection_preference and $collectionQuotes carry customer fields only).
     CHANGE_REQUESTS #125: a collection the client requested itself shows the plan it ticked; when the final price moved beyond its tolerance
     the quotes are listed for the client to re-confirm; once confirmed, who confirmed it. Only for collections. --}}
@if ($asn->isCollection())
    @php($address = $asn->collection_address ?? [])
    @php($plan = $asn->collection_plan ?? [])
    @php($packages = $asn->collection_packages ?? [])
    @php($preference = is_array($asn->collection_preference) && filled($asn->collection_preference['source'] ?? null) ? $asn->collection_preference : null)
    @php($quotes = is_array($collectionQuotes ?? null) ? $collectionQuotes : null)
    @php($selected = $quotes['selected'] ?? null)
    <article class="kv-card" id="collection">
        <strong>{{ __('portal.asns.collection.title') }}: {{ __('portal.asns.collection.modes.we_collect') }}</strong>
        {!! \App\Support\Ui\StatusBadge::render('warehouse.asns.collection.statuses.', $asn->collection_status, $asn->collection_status ? __('portal.asns.collection.statuses.'.$asn->collection_status) : null) !!}
        <p class="text-muted" style="margin:.3rem 0"><small>{{ __('portal.asns.collection.hint') }}</small></p>
        <dl class="kv-2">
            <dt>{{ __('portal.asns.collection.fields.pickup') }}</dt>
            <dd>{{ $address['name'] ?? '—' }}<br><small>{{ $address['address'] ?? '' }}, {{ $address['suburb'] ?? '' }} {{ $address['state'] ?? '' }} {{ $address['postcode'] ?? '' }}</small></dd>
            <dt>{{ __('portal.asns.collection.fields.ready_date') }}</dt>
            <dd>{{ $asn->collection_ready_date?->format('Y-m-d') ?? '—' }}</dd>
            <dt>{{ __('portal.asns.collection.fields.packages') }}</dt>
            <dd>@forelse ($packages as $pkg){{ $pkg['qty'] }} × {{ __('portal.asns.collection.package_types.'.$pkg['package_type']) }} · {{ number_format((float) $pkg['weight_kg'], 1) }} kg @if (! $loop->last)<br>@endif @empty — @endforelse</dd>
            @if ($preference)
                <dt>{{ __('portal.asns.collection.fields.client_choice') }}</dt>
                <dd>{{ ($preference['carrier_name'] ?? null) ?: __('transport.sources.'.$preference['source']) }} · {{ __('transport.service_levels.'.($preference['service_level'] ?? 'standard')) }} · {{ __('portal.asns.collection.fields.customer_price') }} {{ \App\Support\Money::cents((int) ($preference['customer_price_cents'] ?? 0))->format() }}</dd>
            @endif
            <dt>{{ __('portal.asns.collection.fields.plan') }}</dt>
            <dd>
                @if (filled($plan['source'] ?? null))
                    {{ $plan['carrier_name'] ?? __('transport.sources.'.$plan['source']) }} · {{ __('transport.service_levels.'.($plan['service_level'] ?? 'standard')) }} · {{ __('portal.asns.collection.fields.customer_price') }} {{ \App\Support\Money::cents((int) ($plan['customer_price_cents'] ?? 0))->format() }}
                    @if (filled($plan['confirmed_by_type'] ?? null)) · {{ __('portal.asns.collection.fields.confirmed_by') }} {{ __('portal.asns.collection.confirmed_by.'.$plan['confirmed_by_type']) }}@endif
                @elseif ($selected)
                    {{ $selected->carrier_name ?: __('transport.sources.'.$selected->source) }} · {{ __('transport.service_levels.'.$selected->service_level) }} · {{ __('portal.asns.collection.fields.customer_price') }} {{ \App\Support\Money::cents((int) $selected->customer_price_cents)->format() }}
                    @if ($selected->selected_by) · {{ __('portal.asns.collection.fields.confirmed_by') }} {{ __('portal.asns.collection.confirmed_by.'.$selected->selected_by) }}@endif
                @else
                    <span class="text-muted">{{ __('portal.asns.collection.plan_pending') }}</span>
                @endif
            </dd>
            @if ($asn->collection_notes)<dt>{{ __('portal.asns.collection.fields.notes') }}</dt><dd>{{ $asn->collection_notes }}</dd>@endif
        </dl>

        @if ($preference && $quotes && $quotes['can_confirm'] && $selected === null && $quotes['quotes']->isNotEmpty())
            <div id="collection-reconfirm">
                <p style="margin:.5rem 0 .2rem"><strong>{{ __('portal.asns.collection.reconfirm_title') }}</strong><br><small class="text-muted">{{ __('portal.asns.collection.reconfirm_hint') }}</small></p>
                @error('quote')<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
                <div class="overflow-auto"><table class="dense">
                    <thead><tr><th>{{ __('portal.quotes.fields.carrier') }}</th><th>{{ __('portal.quotes.fields.service_level') }}</th><th>{{ __('portal.quotes.fields.eta') }}</th><th class="num">{{ __('portal.quotes.fields.price') }}</th><th>{{ __('portal.quotes.fields.flags') }}</th><th>{{ __('portal.quotes.fields.expires') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($quotes['quotes'] as $quote)
                        <tr>
                            <td>{{ $quote->carrier_name ?: __('transport.sources.'.$quote->source) }}</td>
                            <td>{{ __('transport.service_levels.'.$quote->service_level) }}</td>
                            <td>{{ $quote->eta_days === null ? __('portal.not_provided') : __('orders.estimate.eta_days', ['days' => $quote->eta_days]) }}</td>
                            <td class="num">{{ \App\Support\Money::cents((int) $quote->customer_price_cents)->format() }}</td>
                            <td>
                                @if ($quote->is_recommended)<span class="badge" data-tone="ok">{{ __('orders.estimate.freight_flags.recommended') }}</span>@endif
                                @if ($quote->is_cheapest)<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.cheapest') }}</span>@endif
                                @if ($quote->is_fastest)<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.fastest') }}</span>@endif
                                @if (($preference['source'] ?? null) === $quote->source && ($preference['service_level'] ?? null) === $quote->service_level && ((int) ($preference['carrier_id'] ?? 0) === 0 || (int) $preference['carrier_id'] === (int) $quote->carrier_id))<span class="badge" data-tone="info">{{ __('portal.asns.collection.your_choice') }}</span>@endif
                            </td>
                            <td>
                                {{ $quote->expires_at ? \Illuminate\Support\Carbon::parse($quote->expires_at)->format('Y-m-d H:i') : __('portal.not_provided') }}
                                @if ($quote->expired)<span class="badge" data-tone="warn">{{ __('portal.asns.collection.expired') }}</span>@endif
                            </td>
                            <td>
                                @if ($quote->can_confirm && auth()->user()?->isClientUser())
                                    <form method="post" action="{{ route('portal.asns.collection.quotes.confirm', [$asn, $quote->id]) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="{{ $quote->is_recommended ? '' : 'secondary' }}">{{ __('portal.asns.collection.confirm') }}</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @if ($quotes['all_expired'])<p class="text-muted"><small>{{ __('portal.asns.collection.expired_hint') }}</small></p>@endif
            </div>
        @endif
    </article>
@endif
