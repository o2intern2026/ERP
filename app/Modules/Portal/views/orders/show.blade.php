@extends('layouts.app')

@section('title', $order->order_no)

@section('content')
    <p><a href="{{ route('portal.index') }}">← {{ __('portal.actions.back') }}</a></p>
    <header>
        <h1>{{ $order->order_no }}</h1>
        <p>
            <span class="badge" data-tone="{{ in_array($order->customerStatus(), ['delivered', 'invoiced'], true) ? 'ok' : 'warn' }}">{{ __('orders.customer_statuses.'.$order->customerStatus()) }}</span>
            · {{ __('orders.types.'.$order->order_type) }}
            @if ($order->order_type === 'return' && $order->originalOrder)
                · {{ __('portal.returns.original_order') }}: <a href="{{ route('portal.orders.show', $order->originalOrder) }}">{{ $order->originalOrder->order_no }}</a>
            @endif
        </p>
    </header>

    @if (auth()->user()->isClientUser() && $order->order_type !== 'return')
        {{-- CR #111: one client action per stage — 取消订单 (nothing moved yet) / 申请取消 (picking or packed) / 申请退货 (shipped). --}}
        <article class="kv-card" id="order-actions">
            <strong>{{ __('portal.cancel.title') }}</strong>
            @error('cancel')<p class="text-muted" role="alert" style="color:var(--erp-danger)">{{ $message }}</p>@enderror
            @if ($canCancel)
                <p class="text-muted" style="margin:.3rem 0"><small>{{ __('portal.cancel.cancel_hint') }}</small></p>
                <form method="post" action="{{ route('portal.orders.cancel', $order) }}" class="grid" onsubmit="return confirm(this.dataset.confirm)" data-confirm="{{ __('portal.cancel.confirm') }}">
                    @csrf
                    <input type="text" name="reason" value="{{ old('reason') }}" placeholder="{{ __('portal.cancel.reason') }}" maxlength="255" required>
                    <button type="submit" class="contrast" style="width:auto">{{ __('portal.cancel.cancel') }}</button>
                </form>
            @elseif ($order->isEditableWithApproval())
                @if ($cancelRequest !== null && in_array($cancelRequest->status, ['open', 'in_progress'], true))
                    <p><span class="badge" data-tone="warn">{{ __('portal.cancel.pending_badge') }}</span> {{ __('portal.cancel.pending', ['time' => $cancelRequest->created_at->format('Y-m-d H:i')]) }}</p>
                @else
                    @if ($cancelRequest !== null && $cancelRequest->status === 'resolved')
                        <p><span class="badge" data-tone="danger">{{ __('portal.cancel.rejected_badge') }}</span> {{ $cancelRequest->message }}</p>
                    @endif
                    <p class="text-muted" style="margin:.3rem 0"><small>{{ __('portal.cancel.request_hint') }}</small></p>
                    <form method="post" action="{{ route('portal.orders.cancel_request', $order) }}" class="grid">
                        @csrf
                        <input type="text" name="reason" value="{{ old('reason') }}" placeholder="{{ __('portal.cancel.reason') }}" maxlength="255" required>
                        <button type="submit" class="secondary" style="width:auto">{{ __('portal.cancel.request') }}</button>
                    </form>
                @endif
            @elseif ($canRequestReturn)
                <p class="text-muted" style="margin:.3rem 0"><small>{{ __('portal.cancel.shipped_note') }}</small></p>
                <p><a role="button" class="secondary" href="#return-request" onclick="document.getElementById('return-request').open = true">{{ __('portal.returns.request') }}</a></p>
            @elseif ($order->operational_status === 'cancelled')
                <p class="text-muted"><small>{{ __('portal.cancel.already_cancelled') }}</small></p>
            @else
                <p class="text-muted"><small>{{ __('portal.cancel.none') }}</small></p>
            @endif
        </article>
    @endif

    <h2>{{ __('portal.sections.instruction') }}</h2>
    <dl class="kv kv-2">
        <dt>{{ __('portal.fields.reference') }}</dt><dd>{{ $order->external_ref ?: __('portal.not_provided') }}</dd>
        <dt>{{ __('portal.fields.consignment_mark') }}</dt><dd>{{ $order->consignment_mark ?: __('portal.not_provided') }}</dd>
        <dt>{{ __('portal.fields.fba_reference') }}</dt><dd>{{ $order->fba_reference ?: __('portal.not_provided') }}</dd>
        <dt>{{ __('portal.fields.requested_date') }}</dt><dd>{{ $order->requested_date->format('Y-m-d') }}</dd>
        <dt>{{ __('portal.fields.service_level') }}</dt><dd>{{ __('orders.service_levels.'.$order->service_level) }}</dd>
        <dt>{{ __('portal.fields.tailgate') }}</dt><dd>{{ $order->tailgate_required ? __('portal.tailgate.required') : __('portal.tailgate.not_required') }}</dd>
    </dl>

    <h2>{{ __($order->order_type === 'return' ? 'portal.returns.pickup_title' : 'portal.sections.delivery') }}</h2>
    @if ($order->order_type === 'return' && $order->pickup_address)
        <dl class="kv">
            <dt>{{ __('portal.pickup.name') }}</dt><dd>{{ ($order->pickup_address['name'] ?? null) ?: __('portal.not_provided') }}</dd>
            <dt>{{ __('portal.pickup.phone') }}</dt><dd>{{ ($order->pickup_address['phone'] ?? null) ?: __('portal.not_provided') }}</dd>
            <dt>{{ __('portal.pickup.address') }}</dt><dd>{{ $order->pickup_address['address'] ?? '' }}, {{ $order->pickup_address['suburb'] ?? '' }} {{ $order->pickup_address['state'] ?? '' }} {{ $order->pickup_address['postcode'] ?? '' }}</dd>
        </dl>
    @else
        <dl class="kv">
            <dt>{{ __('portal.fields.deliver_to_name') }}</dt><dd>{{ $order->deliver_to_name }}</dd>
            <dt>{{ __('portal.fields.deliver_to_phone') }}</dt><dd>{{ $order->deliver_to_phone ?: __('portal.not_provided') }}</dd>
            <dt>{{ __('portal.fields.address') }}</dt><dd>{{ $order->deliver_to_address }}, {{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }} {{ $order->deliver_to_postcode }}</dd>
            <dt>{{ __('portal.fields.address_type') }}</dt><dd>{{ $order->deliver_to_address_type ? __('orders.address_types.'.$order->deliver_to_address_type) : __('portal.not_provided') }}</dd>
            <dt>{{ __('portal.fields.delivery_instructions') }}</dt><dd>{{ $order->delivery_instructions ?: __('portal.not_provided') }}</dd>
        </dl>
    @endif

    @if ($order->order_type === 'pickup_deliver' && $order->pickup_address)
        <h3>{{ __('portal.sections.pickup') }}</h3>
        <dl class="kv">
            <dt>{{ __('portal.pickup.name') }}</dt><dd>{{ ($order->pickup_address['name'] ?? null) ?: __('portal.not_provided') }}</dd>
            <dt>{{ __('portal.pickup.phone') }}</dt><dd>{{ ($order->pickup_address['phone'] ?? null) ?: __('portal.not_provided') }}</dd>
            <dt>{{ __('portal.pickup.address') }}</dt><dd>{{ $order->pickup_address['address'] ?? '' }}, {{ $order->pickup_address['suburb'] ?? '' }} {{ $order->pickup_address['state'] ?? '' }} {{ $order->pickup_address['postcode'] ?? '' }}</dd>
        </dl>
    @endif

    @if ($order->order_type !== 'return')
        @include('orders::partials.estimate', ['estimate' => $estimate, 'canEstimate' => $canEstimate, 'estimateRoute' => route('portal.orders.estimate', $order), 'staff' => false])
    @endif

    <h2>{{ __('portal.sections.goods') }}</h2>
    <div class="overflow-auto">
        <table class="dense">
            <thead><tr><th>{{ __('portal.fields.description') }}</th><th>{{ __('portal.fields.package_type') }}</th><th>{{ __('portal.fields.carton_qty') }}</th><th>{{ __('portal.fields.shipped_qty') }}</th><th>{{ __('portal.fields.weight_kg') }}</th></tr></thead>
            <tbody>
                @foreach ($order->lines as $line)
                    <tr><td>{{ $line->description_cn ?: $line->description_en }}</td><td>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($line->package_type) }}</td><td>{{ $line->carton_qty }}</td><td>{{ $line->qty_shipped }}</td><td>{{ $line->actual_weight_kg ?? __('portal.not_provided') }}</td></tr>
                @endforeach
                @foreach ($order->declaredPackages as $package)
                    <tr><td>{{ __('portal.fields.declared_package') }}</td><td>{{ \App\Modules\Orders\OrderEnums::packageTypeLabel($package->package_type) }}</td><td>{{ $package->qty }}</td><td>—</td><td>{{ $package->weight_kg ?? __('portal.not_provided') }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($order->fulfilments->isNotEmpty())
        <h2>{{ __('portal.sections.fulfilments') }}</h2>
        @foreach ($order->fulfilments as $fulfilment)
            <article>
                <header><strong>{{ $order->order_no }}-{{ $fulfilment->seq }}</strong> · {{ __('orders.fulfilment_batch_statuses.'.$fulfilment->status) }}</header>
                <ul>
                    @foreach ($fulfilment->lines as $line)
                        <li>{{ $line->orderLine->description_cn ?: $line->orderLine->description_en }} × {{ $line->qty }}</li>
                    @endforeach
                </ul>
            </article>
        @endforeach
    @endif

    @if ($order->order_type !== 'return' && $transportQuotes !== [])
        <h2>{{ __('portal.quotes.title') }}</h2>
        <p class="text-muted"><small>{{ __('portal.quotes.hint') }}</small></p>
        @foreach ($transportQuotes as $entry)
            <article>
                <header><strong>{{ $entry['shipment']->shipment_no }}</strong>
                    @if ($entry['shipment']->fulfilment_id) · {{ __('portal.quotes.batch') }} @endif
                    · <span class="badge" data-tone="{{ $entry['can_confirm'] ? 'warn' : 'ok' }}">{{ __('portal.shipment_statuses.'.$entry['shipment']->status) }}</span>
                </header>
                @if ($entry['quotes']->isEmpty())
                    <p class="text-muted">{{ __('portal.quotes.awaiting') }}</p>
                @elseif (! $entry['can_confirm'] && $entry['selected'])
                    <p>
                        <strong>{{ __('portal.quotes.confirmed_choice') }}:</strong>
                        {{ $entry['selected']->carrier_name ?: __('orders.estimate.sources.'.$entry['selected']->source) }} · {{ __('orders.service_levels.'.$entry['selected']->service_level) }}
                        · {{ \App\Support\Money::cents((int) $entry['selected']->customer_price_cents)->format() }}
                        @if ($entry['selected']->eta_days !== null) · {{ __('orders.estimate.eta_days', ['days' => $entry['selected']->eta_days]) }}@endif
                        @if ($entry['selected']->selected_by) · <small class="text-muted">{{ __('portal.quotes.confirmed_by.'.$entry['selected']->selected_by) }}</small>@endif
                    </p>
                @else
                    <div class="overflow-auto">
                        <table class="dense">
                            <thead><tr><th>{{ __('portal.quotes.fields.carrier') }}</th><th>{{ __('portal.quotes.fields.service_level') }}</th><th>{{ __('portal.quotes.fields.eta') }}</th><th class="num">{{ __('portal.quotes.fields.price') }}</th><th>{{ __('portal.quotes.fields.flags') }}</th><th>{{ __('portal.quotes.fields.expires') }}</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($entry['quotes'] as $quote)
                                    <tr>
                                        <td>{{ $quote->carrier_name ?: __('orders.estimate.sources.'.$quote->source) }}</td>
                                        <td>{{ __('orders.service_levels.'.$quote->service_level) }}</td>
                                        <td>{{ $quote->eta_days === null ? __('portal.not_provided') : __('orders.estimate.eta_days', ['days' => $quote->eta_days]) }}</td>
                                        <td class="num">{{ \App\Support\Money::cents((int) $quote->customer_price_cents)->format() }}</td>
                                        <td>
                                            @if ($quote->is_recommended)<span class="badge" data-tone="ok">{{ __('orders.estimate.freight_flags.recommended') }}</span>@endif
                                            @if ($quote->is_cheapest)<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.cheapest') }}</span>@endif
                                            @if ($quote->is_fastest)<span class="badge" data-tone="muted">{{ __('orders.estimate.freight_flags.fastest') }}</span>@endif
                                        </td>
                                        <td>
                                            {{ $quote->expires_at ? \Carbon\Carbon::parse($quote->expires_at)->format('Y-m-d H:i') : __('portal.not_provided') }}
                                            @if ($quote->expired)<span class="badge" data-tone="warn">{{ __('portal.quotes.expired') }}</span>@endif
                                        </td>
                                        <td>
                                            @if ($quote->status === 'selected')
                                                <span class="badge" data-tone="ok">{{ __('portal.quotes.confirmed_choice') }}</span>
                                            @elseif ($quote->expired)
                                                <small class="text-muted">{{ __('portal.quotes.expired') }}</small>
                                            @elseif ($quote->can_confirm && auth()->user()->isClientUser())
                                                <form method="post" action="{{ route('portal.orders.quotes.confirm', [$order, $quote->id]) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="{{ $quote->is_recommended ? '' : 'secondary' }}">{{ __('portal.quotes.confirm') }}</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($entry['all_expired'])
                        {{-- 2026-09-10 audit: QuoteSelectionService refuses expired quotes and the portal has no re-quote action — say so instead of a dead button. --}}
                        <p class="text-muted"><small>{{ __('portal.quotes.expired_hint') }}</small></p>
                    @endif
                @endif
            </article>
        @endforeach
    @endif

    <h2>{{ __('portal.sections.tracking') }}</h2>
    @forelse ($shipments as $shipment)
        <article>
            <header><strong>{{ $shipment->shipment_no }}</strong>
                @if ($shipment->carrier) · {{ $shipment->carrier->name }} @endif
                @if ($shipment->tracking_number) · {{ __('portal.fields.tracking_number') }} {{ $shipment->tracking_number }} @endif
                · <span class="badge">{{ __('portal.shipment_statuses.'.$shipment->status) }}</span>
            </header>
            @if ($shipment->trackingEvents->isEmpty())
                <p class="text-muted">{{ __('portal.tracking.none') }}</p>
            @else
                <ol>
                    @foreach ($shipment->trackingEvents as $event)
                        <li>{{ $event->occurred_at?->format('Y-m-d H:i') }} · {{ $event->status }}@if ($event->location) · {{ $event->location }}@endif @if ($event->description)<br><small>{{ $event->description }}</small>@endif</li>
                    @endforeach
                </ol>
            @endif
            @foreach ($shipment->pods->whereNotNull('delivered_at') as $pod)
                <p>
                    {{ __('portal.tracking.delivered_to', ['name' => $pod->recipient_name ?: __('portal.not_provided'), 'at' => $pod->delivered_at?->format('Y-m-d H:i')]) }}
                    @if ($pod->podDocument && $pod->podDocument->client_visible)
                        · <a href="{{ route('portal.documents.download', $pod->podDocument) }}">{{ __('portal.tracking.download_pod') }}</a>
                    @endif
                </p>
            @endforeach
        </article>
    @empty
        <p class="text-muted">{{ __('portal.tracking.no_shipment') }}</p>
    @endforelse

    @if ($order->returnOrders->isNotEmpty())
        <h2>{{ __('portal.returns.title') }}</h2>
        <ul>
            @foreach ($order->returnOrders as $return)
                <li><a href="{{ route('portal.orders.show', $return) }}">{{ $return->order_no }}</a> · {{ __('orders.customer_statuses.'.$return->customerStatus()) }}</li>
            @endforeach
        </ul>
    @endif
    @if ($canRequestReturn && auth()->user()->isClientUser())
        {{-- 2026-09-10 audit: a rejected request comes back with the panel open and the typed reason / quantities kept. --}}
        <details id="return-request"{{ $errors->has('return') ? ' open' : '' }}>
            <summary>{{ __('portal.returns.request') }}</summary>
            <p class="text-muted"><small>{{ __('portal.returns.hint') }}</small></p>
            <form method="post" action="{{ route('portal.orders.returns.store', $order) }}">
                @csrf
                @foreach ($order->lines as $line)
                    <label>{{ $line->description_cn ?: $line->description_en }} · {{ __('portal.returns.return_qty') }}
                        <input type="number" name="quantities[{{ $line->id }}]" min="0" max="{{ $line->qty_shipped ?: $line->carton_qty }}" value="{{ old('quantities.'.$line->id, $line->qty_shipped ?: $line->carton_qty) }}">
                    </label>
                @endforeach
                <input type="text" name="reason" value="{{ old('reason') }}" placeholder="{{ __('portal.returns.reason') }}" required>
                <button type="submit" class="secondary">{{ __('portal.returns.submit') }}</button>
            </form>
        </details>
    @elseif ($order->acceptsReturnRequest() && $order->lines->isEmpty() && auth()->user()->isClientUser())
        {{-- Pure transport order: declared packages only, so the return chain has no goods line to pick — ask 客服 instead of showing a dead form. --}}
        <p class="text-muted"><small>{{ __('portal.returns.pure_transport') }}</small></p>
    @endif

    <h2>{{ __('portal.sections.timeline') }}</h2>
    <ol>
        @foreach ($timeline as $event)
            <li>{{ $event->created_at->format('Y-m-d H:i') }} · <strong>{{ __('orders.customer_statuses.'.\App\Modules\Orders\OrderEnums::CUSTOMER_STATUS_MAP[$event->to_status]) }}</strong></li>
        @endforeach
    </ol>
@endsection
