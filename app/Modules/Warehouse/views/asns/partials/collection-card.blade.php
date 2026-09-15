{{-- 到仓方式 card on the ASN page (CHANGE_REQUESTS #124): 客户自送 with a switch to 我方上门提货, or the collection request with Transport's
     progress (status, 运单号, confirmed plan / 客户价), 修改 and 改为客户自送 while the ASN is booked and Transport has not booked the collection. --}}
@php
    $address = $asn->collection_address ?? [];
    $plan = $asn->collection_plan ?? [];
    $packages = $asn->collection_packages ?? [];
    $formOpen = $errors->has('collection') || collect($errors->keys())->contains(fn ($k) => str_starts_with($k, 'collection'));
@endphp
<article class="kv-card" id="collection">
    <strong>{{ __('warehouse.asns.collection.title') }}: {{ __('warehouse.asns.collection.modes.'.($asn->inbound_transport ?: 'client_delivers')) }}</strong>
    @if ($asn->isCollection())
        {!! \App\Support\Ui\StatusBadge::render('warehouse.asns.collection.statuses.', $asn->collection_status) !!}
        <dl class="kv-2" style="margin-top:.5rem">
            <dt>{{ __('warehouse.asns.collection.fields.pickup') }}</dt>
            <dd>{{ $address['name'] ?? '—' }} @if (filled($address['phone'] ?? null))· {{ $address['phone'] }}@endif<br><small>{{ $address['address'] ?? '' }}, {{ $address['suburb'] ?? '' }} {{ $address['state'] ?? '' }} {{ $address['postcode'] ?? '' }} · {{ __('warehouse.asns.collection.address_types.'.($address['type'] ?? 'business')) }}</small></dd>
            <dt>{{ __('warehouse.asns.collection.fields.ready_date') }}</dt>
            <dd>{{ $asn->collection_ready_date?->format('Y-m-d') ?? '—' }}</dd>
            <dt>{{ __('warehouse.asns.collection.fields.packages') }}</dt>
            <dd>@forelse ($packages as $pkg){{ $pkg['qty'] }} × {{ __('warehouse.asns.collection.package_types.'.$pkg['package_type']) }} · {{ number_format((float) $pkg['weight_kg'], 1) }} kg · {{ $pkg['length_mm'] }}×{{ $pkg['width_mm'] }}×{{ $pkg['height_mm'] }} mm @if (! $loop->last)<br>@endif @empty<span class="text-muted">{{ __('warehouse.asns.collection.packages_from_lines') }}</span>@endforelse</dd>
            <dt>{{ __('warehouse.asns.collection.fields.shipment') }}</dt>
            <dd>@if ($asn->collection_shipment_id)<a href="{{ route('transport.shipments.show', $asn->collection_shipment_id) }}">{{ $plan['shipment_no'] ?? ('#'.$asn->collection_shipment_id) }}</a>@if (filled($plan['booking_ref'] ?? null)) <small class="text-muted">· {{ __('warehouse.asns.collection.fields.booking_ref') }} {{ $plan['booking_ref'] }}</small>@endif @else<span class="text-muted">{{ __('warehouse.asns.collection.shipment_pending') }}</span>@endif</dd>
            <dt>{{ __('warehouse.asns.collection.fields.plan') }}</dt>
            <dd>@if (filled($plan['source'] ?? null)){{ $plan['carrier_name'] ?? __('transport.sources.'.$plan['source']) }} · {{ __('transport.service_levels.'.($plan['service_level'] ?? 'standard')) }} · {{ __('warehouse.asns.collection.fields.customer_price') }} {{ \App\Support\Money::cents((int) ($plan['customer_price_cents'] ?? 0))->format() }}@else<span class="text-muted">{{ __('warehouse.asns.collection.plan_pending') }}</span>@endif</dd>
            @php($preference = is_array($asn->collection_preference) ? $asn->collection_preference : null)
            @if ($preference && filled($preference['source'] ?? null))
                {{-- CHANGE_REQUESTS #125: the plan the client ticked in the portal — the customer price, never a cost. --}}
                <dt>{{ __('warehouse.asns.collection.fields.client_choice') }}</dt>
                <dd>{{ ($preference['carrier_name'] ?? null) ?: __('transport.sources.'.$preference['source']) }} · {{ __('transport.service_levels.'.($preference['service_level'] ?? 'standard')) }} · {{ __('warehouse.asns.collection.fields.customer_price') }} {{ \App\Support\Money::cents((int) ($preference['customer_price_cents'] ?? 0))->format() }}@if (filled($preference['chosen_at'] ?? null)) <small class="text-muted">· {{ __('warehouse.asns.collection.fields.chosen_at') }} {{ \Illuminate\Support\Carbon::parse($preference['chosen_at'])->format('Y-m-d H:i') }}</small>@endif</dd>
            @endif
            @if ($asn->collection_requested_via === 'client')
                <dt>{{ __('warehouse.asns.collection.fields.origin') }}</dt>
                <dd>{{ __('warehouse.asns.collection.origins.client') }}@if ($asn->collection_import_id) <a href="{{ route('orders.imports.show', $asn->collection_import_id) }}">#{{ $asn->collection_import_id }}</a>@endif</dd>
            @endif
            @if ($asn->collection_notes)<dt>{{ __('warehouse.asns.collection.fields.notes') }}</dt><dd>{{ $asn->collection_notes }}</dd>@endif
            <dt>{{ __('warehouse.asns.collection.fields.requested') }}</dt>
            <dd>{{ $asn->collection_requested_at?->format('Y-m-d H:i') ?? '—' }} · {{ $asn->collectionRequestedBy?->name ?? '—' }} <small class="text-muted">· v{{ $asn->collection_version }}</small></dd>
        </dl>
        @if ($canManageCollection)
            @error('collection')<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
            <div class="grid">
                <details id="collection-edit"{{ $formOpen ? ' open' : '' }}>
                    <summary>{{ __('warehouse.asns.collection.edit') }}</summary>
                    <p class="text-muted"><small>{{ __('warehouse.asns.collection.edit_hint') }}</small></p>
                    @if (is_array($asn->collection_preference))<p class="text-muted"><small>{{ __('warehouse.asns.collection.edit_client_hint') }}</small></p>@endif
                    @foreach ($errors->keys() as $key)@if (str_starts_with($key, 'collection') && $key !== 'collection')<p role="alert" style="color:var(--erp-danger)">{{ $errors->first($key) }}</p>@endif @endforeach
                    <form method="post" action="{{ route('warehouse.asns.collection.update', $asn) }}">
                        @csrf @method('put')
                        @include('warehouse::asns.partials.collection-fields', ['asn' => $asn, 'idPrefix' => 'edit-collection'])
                        <button type="submit" style="width:auto;margin-top:.5rem">{{ __('warehouse.asns.collection.submit_edit') }}</button>
                    </form>
                </details>
                <form method="post" action="{{ route('warehouse.asns.collection.destroy', $asn) }}" class="inline">
                    @csrf @method('delete')
                    <button type="submit" class="secondary outline">{{ __('warehouse.asns.collection.switch_to_client') }}</button>
                </form>
            </div>
        @elseif ($asn->collectionLocked())
            <p class="text-muted"><small>{{ __('warehouse.asns.collection.locked_hint') }}</small></p>
        @endif
    @else
        <p class="text-muted" style="margin:.3rem 0"><small>{{ __('warehouse.asns.collection.mode_hints.client_delivers') }}</small></p>
        @if ($canManageCollection)
            @error('collection')<p role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
            <details id="collection-request"{{ $formOpen ? ' open' : '' }}>
                <summary>{{ __('warehouse.asns.collection.switch_to_collect') }}</summary>
                <p class="text-muted"><small>{{ __('warehouse.asns.collection.request_hint') }}</small></p>
                @foreach ($errors->keys() as $key)@if (str_starts_with($key, 'collection') && $key !== 'collection')<p role="alert" style="color:var(--erp-danger)">{{ $errors->first($key) }}</p>@endif @endforeach
                <form method="post" action="{{ route('warehouse.asns.collection.update', $asn) }}">
                    @csrf @method('put')
                    @include('warehouse::asns.partials.collection-fields', ['asn' => $asn, 'idPrefix' => 'request-collection'])
                    <button type="submit" style="width:auto;margin-top:.5rem">{{ __('warehouse.asns.collection.submit_request') }}</button>
                </form>
            </details>
        @endif
    @endif
</article>
