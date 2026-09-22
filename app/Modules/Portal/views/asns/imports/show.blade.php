@extends('layouts.app')

@section('title', __('portal.inbound.show_title', ['id' => $import->id]))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a> · <a href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.list_title') }}</a></p>
    <header>
        <h1>{{ __('portal.inbound.show_title', ['id' => $import->id]) }}</h1>
        <p>
            {!! \App\Support\Ui\StatusBadge::render('portal.inbound.statuses.', $import->status) !!}
            · {{ $manual ? __('portal.inbound.manual.source') : ($audit['context']['original_name'] ?? '') }} · {{ $import->created_at?->format('Y-m-d H:i') }}
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
            {{-- CHANGE_REQUESTS #143: the import options this list was read with, and how many orders the grouping rule produces. --}}
            <dt>{{ __('portal.inbound.options.group_by') }}</dt><dd>{{ __('portal.inbound.options.group_by_options.'.$groupBy) }}@if ($groups->isNotEmpty()) · {{ __('portal.inbound.options.preview_orders', ['count' => $import->status === 'pending' ? $readyCount : count($audit['result']['created'] ?? [])]) }}@endif</dd>
            <dt>{{ __('portal.inbound.options.address_type_default') }}</dt><dd>{{ __('portal.inbound.options.address_type_defaults.'.$addressTypeDefault) }}</dd>
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
                        <tr><td>{{ $pkg['label'] ?? ($pkg['row'] ?? '—') }}</td><td>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($pkg['package_type'] ?? null) }}</td><td class="num">{{ $pkg['qty'] }}</td><td class="num">{{ $pkg['weight_kg'] ?? '—' }}</td><td>@if (($pkg['length_mm'] ?? null) || ($pkg['width_mm'] ?? null) || ($pkg['height_mm'] ?? null)){{ $pkg['length_mm'] ?? '—' }}×{{ $pkg['width_mm'] ?? '—' }}×{{ $pkg['height_mm'] ?? '—' }}@else — @endif</td></tr>
                    @endforeach</tbody>
                </table></div>
            @endif
            @if ($collectionUnpriced !== [])
                {{-- CHANGE_REQUESTS #128: an entry may be an attached order's number instead of a typed row number. --}}
                @php($mixed = collect($collectionUnpriced)->contains(fn ($ref) => ! is_int($ref)))
                @php($refs = collect($collectionUnpriced)->map(fn ($ref) => is_int($ref) ? __('portal.inbound.collection.row_ref', ['row' => $ref]) : __('portal.inbound.collection.order_ref', ['order_no' => $ref]))->implode(', '))
                <p class="text-muted" style="margin:.3rem 0"><small>{{ $mixed ? __('portal.inbound.collection.unpriced_mixed', ['rows' => $refs]) : __('portal.inbound.collection.unpriced', ['rows' => implode(', ', $collectionUnpriced)]) }}</small></p>
            @endif
        </article>
    @endif

    @if ($attachedOrders->isNotEmpty())
        {{-- CHANGE_REQUESTS #128 以订单为准: the existing orders this manual list attached, read from the ORDER — no form fields, nothing of them changes. --}}
        <article class="kv-card" id="attached-orders">
            <strong>{{ __('portal.inbound.manual.attached_title') }}</strong>
            <p class="text-muted"><small>{{ __('portal.inbound.manual.attached_hint') }}</small></p>
            <div class="overflow-auto"><table class="dense">
                <thead><tr>
                    <th>{{ __('portal.inbound.manual.attached_columns.order_no') }}</th><th>{{ __('portal.inbound.manual.attached_columns.mark') }}</th><th>{{ __('portal.inbound.manual.attached_columns.consignee') }}</th>
                    <th>{{ __('portal.inbound.manual.attached_columns.address') }}</th><th>{{ __('portal.inbound.manual.attached_columns.fba') }}</th>
                    <th>{{ __('portal.inbound.manual.attached_columns.goods') }}</th><th>{{ __('portal.inbound.manual.attached_columns.package_type') }}</th><th class="num">{{ __('portal.inbound.manual.attached_columns.cartons') }}</th>
                    <th class="num">{{ __('portal.inbound.manual.attached_columns.weight') }}</th><th>{{ __('portal.inbound.manual.attached_columns.dims') }}</th>
                    <th>{{ __('portal.inbound.manual.attached_columns.requested_date') }}</th><th>{{ __('portal.inbound.manual.attached_columns.status') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($attachedOrders as $order)
                    @php($orderLines = $order->lines->whereNull('asn_line_id')->values())
                    @php($orderSpan = max(1, $orderLines->count()))
                    @foreach ($orderLines->isEmpty() ? [null] : $orderLines as $line)
                        <tr>
                            @if ($loop->first)
                                <td rowspan="{{ $orderSpan }}"><a href="{{ route('portal.orders.show', $order) }}">{{ $order->order_no }}</a></td>
                                <td rowspan="{{ $orderSpan }}"><strong>{{ $order->consignment_mark ?: '—' }}</strong></td>
                                <td rowspan="{{ $orderSpan }}">{{ $order->deliver_to_name }}<br><small>{{ $order->deliver_to_phone ?: '—' }}</small></td>
                                <td rowspan="{{ $orderSpan }}">{{ $order->deliver_to_address }}, {{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }} {{ $order->deliver_to_postcode }}</td>
                                <td rowspan="{{ $orderSpan }}">{{ $order->fba_reference ?: '—' }}</td>
                            @endif
                            <td>{{ $line ? (trim(implode(' / ', array_filter([$line->description_cn, $line->description_en]))) ?: '—') : '—' }}</td>
                            <td>{{ $line ? \App\Modules\Orders\OrderEnums::packageTypeLabel($line->package_type) : '—' }}</td>
                            <td class="num">{{ $line ? (int) $line->carton_qty : '—' }}</td>
                            <td class="num">{{ $line?->actual_weight_kg ?? '—' }}</td>
                            <td>@if ($line && ($line->length_mm || $line->width_mm || $line->height_mm)){{ $line->length_mm ?? '—' }}×{{ $line->width_mm ?? '—' }}×{{ $line->height_mm ?? '—' }}@else — @endif</td>
                            @if ($loop->first)
                                <td rowspan="{{ $orderSpan }}">{{ $order->requested_date?->format('Y-m-d') ?? '—' }}</td>
                                <td rowspan="{{ $orderSpan }}">
                                    {!! \App\Support\Ui\StatusBadge::render('orders.statuses.operational.', $order->operational_status) !!}
                                    @if (isset($asns[(int) $order->id]))<br><small>{{ __('portal.inbound.fields.asn') }} {{ implode(', ', $asns[(int) $order->id]) }}</small>@endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table></div>
        </article>
    @endif

    @if ($import->status === 'pending')
        <p>{{ __('portal.inbound.ready_count', ['ready' => $readyCount, 'blocked' => $blockedCount, 'errors' => $errorRows]) }}@if ($attachedOrders->isNotEmpty()) {{ __('portal.inbound.manual.attached_count', ['count' => $attachedOrders->count()]) }}@endif</p>
    @endif
    @if ($tierSurcharge !== [])
        <p class="text-muted"><small>{{ __('portal.inbound.tier_surcharge', ['percent' => count(array_unique($tierSurcharge)) === 1 ? collect($tierSurcharge)->first() : collect($tierSurcharge)->map(fn ($p, $code) => $code.' '.$p)->implode(' · ')]) }}</small></p>
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
        {{-- CHANGE_REQUESTS #143: a 300-row consolidation list can carry hundreds of identical notes (phones padded) — folded behind a count past 20. --}}
        <article>
            @if (count($audit['warnings']) > 20)
                <details><summary><strong>{{ __('portal.inbound.sections.warnings') }}</strong> · {{ __('portal.inbound.warnings_folded', ['count' => count($audit['warnings'])]) }}</summary><ul>@foreach ($audit['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul></details>
            @else
                <strong>{{ __('portal.inbound.sections.warnings') }}</strong><ul>@foreach ($audit['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul>
            @endif
        </article>
    @endif

    @if ($groups->isNotEmpty())
        <h2>{{ __('portal.inbound.sections.groups') }}</h2>
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>{{ __('portal.inbound.fields.mark') }}</th><th>{{ __('portal.inbound.fields.consignee') }}</th><th>{{ __('portal.inbound.fields.address') }}</th>
                <th>{{ __('portal.inbound.fields.suburb') }}</th><th>{{ __('portal.inbound.fields.state') }}</th><th>{{ __('portal.inbound.fields.postcode') }}</th><th>{{ __('portal.inbound.fields.address_type') }}</th><th>{{ __('portal.inbound.fields.fba') }}</th>
                <th>{{ __('portal.inbound.fields.goods') }}</th><th>{{ __('portal.inbound.fields.package_type') }}</th><th class="num">{{ __('portal.inbound.fields.cartons') }}</th>
                <th class="num">{{ __('portal.inbound.fields.weight') }}</th><th>{{ __('portal.inbound.fields.dims') }}</th><th>{{ __('portal.inbound.fields.storage_tier') }}</th><th>{{ __('portal.inbound.fields.row_numbers') }}</th><th>{{ __('portal.inbound.fields.status') }}</th>
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
                            {{-- CHANGE_REQUESTS #136: the address type the order will carry; a residential consignee is flagged because OMS-13 sets tailgate_required on it. --}}
                            @php($addressType = $group['deliver_to_address_type'] ?? 'business')
                            <td rowspan="{{ $span }}">@if ($addressType === 'residential')<span class="badge" data-tone="warn">{{ __('portal.inbound.residential_tailgate') }}</span>@else{{ __('portal.inbound.address_types.'.$addressType) }}@endif</td>
                            <td rowspan="{{ $span }}">{{ $group['fba_reference'] ?: '—' }}</td>
                        @endif
                        <td>{{ trim(implode(' / ', array_filter([$row['description_cn'] ?? null, $row['description_en'] ?? null]))) ?: '—' }}</td>
                        <td>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($row['package_type'] ?? null) }}</td>
                        <td class="num">{{ $row['carton_qty'] }}</td>
                        <td class="num">{{ $row['actual_weight_kg'] ?? '—' }}</td>
                        <td>@if ($row['length_mm'] || $row['width_mm'] || $row['height_mm']){{ $row['length_mm'] ?? '—' }}×{{ $row['width_mm'] ?? '—' }}×{{ $row['height_mm'] ?? '—' }}@else — @endif</td>
                        <td>@if (($row['storage_tier'] ?? null) === 'bottom')<span class="badge" data-tone="warn">{{ __('portal.stock.storage_tiers.bottom') }}</span>@else{{ __('portal.stock.storage_tiers.standard') }}@endif @if (($row['storage_tier_source'] ?? null) === 'value_rule')<br><span class="badge" data-tone="info">{{ __('portal.inbound.tier_prefilled') }}</span>@endif</td>
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

    @php($againRoute = $manual ? route('portal.asns.imports.manual.create') : route('portal.asns.imports.create'))
    @php($againLabel = $manual ? __('portal.inbound.manual.button') : __('portal.inbound.actions.reupload'))
    @if ($import->status === 'pending')
        @if ($readyCount > 0 || $attachedOrders->isNotEmpty())
            <form method="post" action="{{ route('portal.asns.imports.confirm', $import) }}">
                @csrf
                @if ($collection && $collectionEstimate !== null)
                    {{-- CHANGE_REQUESTS #125: the collection plans with CLIENT prices (customer fields only); the client ticks one, the server re-prices and trusts only the key. --}}
                    @php($plans = $collectionEstimate['options'])
                    <article class="kv-card" id="collection-plans">
                        <strong>{{ __('portal.inbound.collection.plans_title') }}</strong>
                        @if (($collectionEstimate['reason'] ?? null) === 'no_items')
                            {{-- Review UX-1: no row can be collected by (weight + L/W/H) — confirm is refused, the client re-uploads. --}}
                            <p role="alert" style="margin:.3rem 0;color:var(--erp-danger)">{{ __('portal.inbound.collection.errors.no_items') }}</p>
                        @elseif ($plans === [])
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
                @unless ($collection && ($collectionEstimate['reason'] ?? null) === 'no_items')
                    @if ($skippedRows > 0)
                        {{-- CHANGE_REQUESTS #136 (audit PORTAL-05): rows that will NOT become orders (not read, or a blocked / duplicate 唛头) — the button says so and a tick is required. --}}
                        <label><input type="checkbox" name="skip_acknowledged" value="1" required @checked(old('skip_acknowledged'))> {{ __('portal.inbound.skip_acknowledge') }}</label>
                        <button type="submit">{{ __('portal.inbound.actions.confirm_partial', ['ready' => $readyCount, 'skipped' => $skippedRows]) }}</button>
                    @else
                        <button type="submit">{{ __('portal.inbound.actions.confirm') }}</button>
                    @endif
                @endunless
                <a class="secondary" role="button" href="{{ $againRoute }}">{{ $againLabel }}</a>
            </form>
        @else
            <p>{{ __('portal.inbound.no_ready') }}</p>
            <a role="button" href="{{ $againRoute }}">{{ $againLabel }}</a>
        @endif
    @else
        <h2>{{ __('portal.inbound.sections.result') }}</h2>
        @if (($audit['result']['created'] ?? []) !== [] || ($audit['result']['attached'] ?? []) !== [])
            <ul>
                @foreach ($audit['result']['created'] as $created)
                    <li>
                        <a href="{{ route('portal.orders.show', $created['order_id']) }}">{{ $created['order_no'] }}</a>
                        · {{ __('portal.inbound.fields.row_numbers') }} {{ implode(', ', $created['row_numbers'] ?? []) }}
                        @if (isset($asns[(int) $created['order_id']]))· {{ __('portal.inbound.fields.asn') }} {{ implode(', ', $asns[(int) $created['order_id']]) }}@endif
                    </li>
                @endforeach
                {{-- CHANGE_REQUESTS #128: the attached orders, unchanged, listed with the ASN staff built for them. --}}
                @foreach ($audit['result']['attached'] ?? [] as $attached)
                    <li>
                        <a href="{{ route('portal.orders.show', $attached['order_id']) }}">{{ $attached['order_no'] }}</a>
                        · {{ __('portal.inbound.manual.attached_title') }}
                        @if (isset($asns[(int) $attached['order_id']]))· {{ __('portal.inbound.fields.asn') }} {{ implode(', ', $asns[(int) $attached['order_id']]) }}@endif
                    </li>
                @endforeach
            </ul>
            <p>{{ __('portal.inbound.after_confirm') }}</p>
        @else
            <p>{{ __('portal.inbound.no_ready') }}</p>
        @endif
        <a role="button" class="secondary" href="{{ $againRoute }}">{{ $againLabel }}</a>
    @endif
@endsection
