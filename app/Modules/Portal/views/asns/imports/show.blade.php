@extends('layouts.app')

@section('title', __('portal.inbound.show_title', ['id' => $import->id]))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a> · <a href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.list_title') }}</a></p>
    <header>
        <h1>{{ __('portal.inbound.show_title', ['id' => $import->id]) }}</h1>
        <p>
            {!! \App\Support\Ui\StatusBadge::render('portal.inbound.statuses.', $import->status) !!}
            · {{ $audit['context']['original_name'] ?? '' }} · {{ $import->created_at?->format('Y-m-d H:i') }}
            @if ($document)· <a href="{{ route('portal.documents.download', $document) }}">{{ __('portal.inbound.actions.download_file') }}</a>@endif
        </p>
    </header>

    @if (session('status'))<article>{{ session('status') }}</article>@endif
    @if ($errors->any())
        <article role="alert"><strong>{{ __('portal.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <article class="kv-card">
        <strong>{{ __('portal.inbound.sections.context') }}</strong>
        <dl class="kv-2">
            <dt>{{ __('portal.inbound.fields.container_no') }}</dt><dd>{{ ($inbound['container_no'] ?? null) ?: '—' }}</dd>
            <dt>{{ __('portal.inbound.fields.container_size') }}</dt><dd>@if (! empty($inbound['container_size'])){{ __('warehouse.container_sizes.'.$inbound['container_size']) }}@else — @endif</dd>
            <dt>{{ __('portal.inbound.fields.expected_date') }}</dt><dd>{{ ($inbound['expected_date'] ?? null) ?: '—' }}</dd>
            <dt>{{ __('portal.inbound.fields.reference') }}</dt><dd>{{ ($inbound['reference'] ?? null) ?: '—' }}</dd>
            <dt>{{ __('portal.inbound.fields.notes') }}</dt><dd>{{ ($inbound['notes'] ?? null) ?: '—' }}</dd>
            <dt>{{ __('portal.inbound.fields.requested_date') }}</dt><dd>{{ $requestedDate ?: '—' }} · {{ __('orders.service_levels.standard') }}</dd>
        </dl>
    </article>

    @if ($collection)
        {{-- CHANGE_REQUESTS #125: 需要我们上门提货 — what the client asked for, the packages derived from the list rows (never typed twice). --}}
        @php($pickup = is_array($collection['address'] ?? null) ? $collection['address'] : [])
        @php($chosen = is_array($collection['preference'] ?? null) ? $collection['preference'] : null)
        <article class="kv-card" id="collection">
            <strong>{{ __('portal.inbound.collection.title') }}</strong>
            <dl class="kv-2">
                <dt>{{ __('portal.inbound.collection.fields.pickup') }}</dt>
                <dd>{{ $pickup['address'] ?? '' }}, {{ $pickup['suburb'] ?? '' }} {{ $pickup['state'] ?? '' }} {{ $pickup['postcode'] ?? '' }} · {{ __('portal.inbound.collection.address_types.'.(($pickup['type'] ?? null) ?: 'business')) }}</dd>
                <dt>{{ __('portal.inbound.collection.fields.contact') }}</dt>
                <dd>{{ ($pickup['name'] ?? null) ?: '—' }} · {{ ($pickup['phone'] ?? null) ?: '—' }}</dd>
                <dt>{{ __('portal.inbound.collection.fields.ready_date') }}</dt>
                <dd>{{ ($collection['ready_date'] ?? null) ?: '—' }}</dd>
                <dt>{{ __('portal.inbound.collection.fields.warehouse') }}</dt>
                <dd>@if ($collectionWarehouse){{ $collectionWarehouse->code }} · {{ $collectionWarehouse->name }}@else — @endif</dd>
                @if (filled($collection['notes'] ?? null))<dt>{{ __('portal.inbound.collection.fields.notes') }}</dt><dd>{{ $collection['notes'] }}</dd>@endif
                @if ($import->status !== 'pending')
                    <dt>{{ __('portal.inbound.collection.chosen') }}</dt>
                    <dd>@if ($chosen && filled($chosen['source'] ?? null)){{ ($chosen['carrier_name'] ?? null) ?: __('transport.sources.'.$chosen['source']) }} · {{ __('orders.service_levels.'.($chosen['service_level'] ?? 'standard')) }} · {{ \App\Support\Money::cents((int) ($chosen['customer_price_cents'] ?? 0))->format() }}@else<span class="text-muted">{{ __('portal.inbound.collection.pending_freight') }}</span>@endif</dd>
                @endif
            </dl>
            @if ($collectionPackages !== [])
                <p style="margin:.4rem 0 .2rem"><small><strong>{{ __('portal.inbound.collection.packages') }}</strong></small></p>
                <div class="overflow-auto"><table class="dense">
                    <thead><tr><th>{{ __('portal.inbound.collection.package_fields.row') }}</th><th>{{ __('portal.inbound.collection.package_fields.package_type') }}</th><th class="num">{{ __('portal.inbound.collection.package_fields.qty') }}</th><th class="num">{{ __('portal.inbound.collection.package_fields.weight') }}</th><th>{{ __('portal.inbound.collection.package_fields.dims') }}</th></tr></thead>
                    <tbody>@foreach ($collectionPackages as $pkg)
                        <tr><td>{{ $pkg['row'] ?? '—' }}</td><td>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($pkg['package_type'] ?? null) }}</td><td class="num">{{ $pkg['qty'] }}</td><td class="num">{{ $pkg['weight_kg'] ?? '—' }}</td><td>@if (($pkg['length_mm'] ?? null) || ($pkg['width_mm'] ?? null) || ($pkg['height_mm'] ?? null)){{ $pkg['length_mm'] ?? '—' }}×{{ $pkg['width_mm'] ?? '—' }}×{{ $pkg['height_mm'] ?? '—' }}@else — @endif</td></tr>
                    @endforeach</tbody>
                </table></div>
            @endif
            @if ($collectionUnpriced !== [])<p class="text-muted" style="margin:.3rem 0"><small>{{ __('portal.inbound.collection.unpriced', ['rows' => implode(', ', $collectionUnpriced)]) }}</small></p>@endif
        </article>
    @endif

    @if ($import->status === 'pending')
        <p>{{ __('portal.inbound.ready_count', ['ready' => $readyCount, 'blocked' => $blockedCount, 'errors' => $errorRows]) }}</p>
    @endif

    @if (($audit['issues'] ?? []) !== [])
        <article>
            <strong>{{ __('portal.inbound.sections.errors') }}</strong>
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th>{{ __('portal.inbound.fields.row') }}</th><th>{{ __('portal.inbound.fields.column') }}</th><th>{{ __('portal.inbound.fields.message') }}</th></tr></thead>
                <tbody>@foreach ($audit['issues'] as $issue)<tr><td>{{ $issue['row'] }}</td><td>{{ $issue['label'] ?? $issue['column'] }}</td><td>{{ $issue['message'] }}</td></tr>@endforeach</tbody>
            </table></div>
        </article>
    @endif

    @if (($audit['warnings'] ?? []) !== [])
        <article><strong>{{ __('portal.inbound.sections.warnings') }}</strong><ul>@foreach ($audit['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul></article>
    @endif

    @if ($groups->isNotEmpty())
        <h2>{{ __('portal.inbound.sections.groups') }}</h2>
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>{{ __('portal.inbound.fields.mark') }}</th><th>{{ __('portal.inbound.fields.consignee') }}</th><th>{{ __('portal.inbound.fields.address') }}</th>
                <th>{{ __('portal.inbound.fields.suburb') }}</th><th>{{ __('portal.inbound.fields.state') }}</th><th>{{ __('portal.inbound.fields.postcode') }}</th><th>{{ __('portal.inbound.fields.fba') }}</th>
                <th>{{ __('portal.inbound.fields.goods') }}</th><th>{{ __('portal.inbound.fields.package_type') }}</th><th class="num">{{ __('portal.inbound.fields.cartons') }}</th>
                <th class="num">{{ __('portal.inbound.fields.weight') }}</th><th>{{ __('portal.inbound.fields.dims') }}</th><th>{{ __('portal.inbound.fields.row_numbers') }}</th><th>{{ __('portal.inbound.fields.status') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($groups as $group)
                @php($span = max(1, count($group['rows'])))
                @foreach ($group['rows'] as $row)
                    <tr>
                        @if ($loop->first)
                            <td rowspan="{{ $span }}"><strong>{{ $group['consignment_mark'] }}</strong></td>
                            <td rowspan="{{ $span }}">{{ $group['deliver_to_name'] }}<br><small>{{ $group['deliver_to_phone'] ?: '—' }}</small></td>
                            <td rowspan="{{ $span }}">{{ $group['deliver_to_address'] }}</td>
                            <td rowspan="{{ $span }}">{{ $group['deliver_to_suburb'] ?: '—' }}</td>
                            <td rowspan="{{ $span }}">{{ $group['deliver_to_state'] }}</td>
                            <td rowspan="{{ $span }}">{{ $group['deliver_to_postcode'] }}</td>
                            <td rowspan="{{ $span }}">{{ $group['fba_reference'] ?: '—' }}</td>
                        @endif
                        <td>{{ trim(implode(' / ', array_filter([$row['description_cn'] ?? null, $row['description_en'] ?? null]))) ?: '—' }}</td>
                        <td>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($row['package_type'] ?? null) }}</td>
                        <td class="num">{{ $row['carton_qty'] }}</td>
                        <td class="num">{{ $row['actual_weight_kg'] ?? '—' }}</td>
                        <td>@if ($row['length_mm'] || $row['width_mm'] || $row['height_mm']){{ $row['length_mm'] ?? '—' }}×{{ $row['width_mm'] ?? '—' }}×{{ $row['height_mm'] ?? '—' }}@else — @endif</td>
                        <td>{{ $row['row'] }}</td>
                        @if ($loop->first)
                            <td rowspan="{{ $span }}">
                                {!! \App\Support\Ui\StatusBadge::render('portal.inbound.group_statuses.', $group['status']) !!}
                                @if ($group['status'] === 'imported' && isset($orders[$group['order_id'] ?? 0]))<br><a href="{{ route('portal.orders.show', $group['order_id']) }}">{{ $orders[$group['order_id']]->order_no }}</a>@endif
                                @if ($group['message'])<br><small>{{ $group['message'] }}</small>@endif
                                @if (! empty($group['requested_date']) || ! empty($group['service_level']))<br><small>{{ $group['requested_date'] ?? $requestedDate }} · {{ __('orders.service_levels.'.($group['service_level'] ?? 'standard')) }}</small>@endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table></div>
    @endif

    @if ($import->status === 'pending')
        @if ($readyCount > 0)
            <form method="post" action="{{ route('portal.asns.imports.confirm', $import) }}">
                @csrf
                @if ($collection && $collectionEstimate !== null)
                    {{-- CHANGE_REQUESTS #125: the collection plans with CLIENT prices (customer fields only); the client ticks one, the server re-prices and trusts only the key. --}}
                    @php($plans = $collectionEstimate['options'])
                    <article class="kv-card" id="collection-plans">
                        <strong>{{ __('portal.inbound.collection.plans_title') }}</strong>
                        @if ($plans === [])
                            <p style="margin:.3rem 0">{{ __('portal.inbound.collection.no_plan.'.($collectionEstimate['reason'] ?? 'none')) }} {{ __('portal.inbound.collection.no_plan_hint') }}</p>
                        @else
                            <p class="text-muted" style="margin:.3rem 0"><small>{{ __('portal.inbound.collection.plans_hint') }}</small></p>
                            @php($checkedKey = old('collection_choice', collect($plans)->firstWhere('is_recommended', true)['key'] ?? $plans[0]['key']))
                            <div class="overflow-auto"><table class="dense" id="collection-options">
                                <thead><tr><th>{{ __('portal.inbound.collection.choose') }}</th><th>{{ __('portal.quotes.fields.carrier') }}</th><th>{{ __('portal.quotes.fields.service_level') }}</th><th>{{ __('portal.quotes.fields.eta') }}</th><th class="num">{{ __('portal.quotes.fields.price') }}</th><th>{{ __('portal.quotes.fields.flags') }}</th></tr></thead>
                                <tbody>@foreach ($plans as $plan)
                                    <tr>
                                        <td><input type="radio" name="collection_choice" value="{{ $plan['key'] }}" aria-label="{{ $plan['key'] }}" @checked($plan['key'] === $checkedKey)></td>
                                        <td>{{ $plan['carrier_name'] ?: __('transport.sources.'.$plan['source']) }}</td>
                                        <td>{{ __('orders.service_levels.'.$plan['service_level']) }}</td>
                                        <td>{{ $plan['eta_days'] === null ? __('portal.not_provided') : __('orders.estimate.eta_days', ['days' => $plan['eta_days']]) }}</td>
                                        <td class="num">{{ \App\Support\Money::cents((int) $plan['customer_price_cents'])->format() }}</td>
                                        <td>
                                            @if ($plan['is_recommended'])<span class="badge" data-tone="ok">{{ __('orders.estimate.freight_flags.recommended') }}</span>@endif
                                            @if ($plan['is_cheapest'])<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.cheapest') }}</span>@endif
                                            @if ($plan['is_fastest'])<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.fastest') }}</span>@endif
                                        </td>
                                    </tr>
                                @endforeach</tbody>
                            </table></div>
                        @endif
                    </article>
                @endif
                <button type="submit">{{ __('portal.inbound.actions.confirm') }}</button>
                <a class="secondary" role="button" href="{{ route('portal.asns.imports.create') }}">{{ __('portal.inbound.actions.reupload') }}</a>
            </form>
        @else
            <p>{{ __('portal.inbound.no_ready') }}</p>
            <a role="button" href="{{ route('portal.asns.imports.create') }}">{{ __('portal.inbound.actions.reupload') }}</a>
        @endif
    @else
        <h2>{{ __('portal.inbound.sections.result') }}</h2>
        @if (($audit['result']['created'] ?? []) !== [])
            <ul>
                @foreach ($audit['result']['created'] as $created)
                    <li>
                        <a href="{{ route('portal.orders.show', $created['order_id']) }}">{{ $created['order_no'] }}</a>
                        · {{ __('portal.inbound.fields.row_numbers') }} {{ implode(', ', $created['row_numbers'] ?? []) }}
                        @if (isset($asns[(int) $created['order_id']]))· {{ __('portal.inbound.fields.asn') }} {{ implode(', ', $asns[(int) $created['order_id']]) }}@endif
                    </li>
                @endforeach
            </ul>
            <p>{{ __('portal.inbound.after_confirm') }}</p>
        @else
            <p>{{ __('portal.inbound.no_ready') }}</p>
        @endif
        <a role="button" class="secondary" href="{{ route('portal.asns.imports.create') }}">{{ __('portal.inbound.actions.reupload') }}</a>
    @endif
@endsection
