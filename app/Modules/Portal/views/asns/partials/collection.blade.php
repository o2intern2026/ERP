{{-- 到仓方式 我方上门提货 in the portal (CHANGE_REQUESTS #124): read-only — pickup address, ready date, progress, the confirmed plan and the
     CLIENT price. Nothing here reads carrier cost or margin (asns.collection_plan carries customer_price_cents only). Only for collections. --}}
@if ($asn->isCollection())
    @php($address = $asn->collection_address ?? [])
    @php($plan = $asn->collection_plan ?? [])
    @php($packages = $asn->collection_packages ?? [])
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
            <dt>{{ __('portal.asns.collection.fields.plan') }}</dt>
            <dd>@if (filled($plan['source'] ?? null)){{ $plan['carrier_name'] ?? __('transport.sources.'.$plan['source']) }} · {{ __('transport.service_levels.'.($plan['service_level'] ?? 'standard')) }} · {{ __('portal.asns.collection.fields.customer_price') }} {{ \App\Support\Money::cents((int) ($plan['customer_price_cents'] ?? 0))->format() }}@else<span class="text-muted">{{ __('portal.asns.collection.plan_pending') }}</span>@endif</dd>
            @if ($asn->collection_notes)<dt>{{ __('portal.asns.collection.fields.notes') }}</dt><dd>{{ $asn->collection_notes }}</dd>@endif
        </dl>
    </article>
@endif
