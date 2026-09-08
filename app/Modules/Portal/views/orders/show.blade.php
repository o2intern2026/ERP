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

    <h2>{{ __('portal.sections.instruction') }}</h2>
    <dl>
        <dt>{{ __('portal.fields.reference') }}</dt><dd>{{ $order->external_ref ?: __('portal.not_provided') }}</dd>
        <dt>{{ __('portal.fields.consignment_mark') }}</dt><dd>{{ $order->consignment_mark ?: __('portal.not_provided') }}</dd>
        <dt>{{ __('portal.fields.fba_reference') }}</dt><dd>{{ $order->fba_reference ?: __('portal.not_provided') }}</dd>
        <dt>{{ __('portal.fields.requested_date') }}</dt><dd>{{ $order->requested_date->format('Y-m-d') }}</dd>
        <dt>{{ __('portal.fields.service_level') }}</dt><dd>{{ __('orders.service_levels.'.$order->service_level) }}</dd>
    </dl>

    <h2>{{ __($order->order_type === 'return' ? 'portal.returns.pickup_title' : 'portal.sections.delivery') }}</h2>
    @if ($order->order_type === 'return' && $order->pickup_address)
        <p>{{ $order->pickup_address['name'] ?? '' }}@if ($order->pickup_address['phone'] ?? null) · {{ $order->pickup_address['phone'] }}@endif<br>
            {{ $order->pickup_address['address'] ?? '' }}, {{ $order->pickup_address['suburb'] ?? '' }} {{ $order->pickup_address['state'] ?? '' }} {{ $order->pickup_address['postcode'] ?? '' }}</p>
    @else
        <p>{{ $order->deliver_to_name }}@if ($order->deliver_to_phone) · {{ $order->deliver_to_phone }}@endif<br>
            {{ $order->deliver_to_address }}, {{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }} {{ $order->deliver_to_postcode }}</p>
        @if ($order->delivery_instructions)<p><small>{{ __('portal.fields.delivery_instructions') }}: {{ $order->delivery_instructions }}</small></p>@endif
    @endif

    @if ($order->order_type === 'pickup_deliver' && $order->pickup_address)
        <h3>{{ __('portal.sections.pickup') }}</h3>
        <p>{{ $order->pickup_address['name'] ?? '' }}<br>{{ $order->pickup_address['address'] ?? '' }}, {{ $order->pickup_address['suburb'] ?? '' }} {{ $order->pickup_address['state'] ?? '' }} {{ $order->pickup_address['postcode'] ?? '' }}</p>
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
                    <tr><td>{{ $line->description_cn ?: $line->description_en }}</td><td>{{ $line->package_type }}</td><td>{{ $line->carton_qty }}</td><td>{{ $line->qty_shipped }}</td><td>{{ $line->actual_weight_kg ?? __('portal.not_provided') }}</td></tr>
                @endforeach
                @foreach ($order->declaredPackages as $package)
                    <tr><td>{{ __('portal.fields.declared_package') }}</td><td>{{ $package->package_type }}</td><td>{{ $package->qty }}</td><td>—</td><td>{{ $package->weight_kg ?? __('portal.not_provided') }}</td></tr>
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
        <details>
            <summary>{{ __('portal.returns.request') }}</summary>
            <p class="text-muted"><small>{{ __('portal.returns.hint') }}</small></p>
            <form method="post" action="{{ route('portal.orders.returns.store', $order) }}">
                @csrf
                @foreach ($order->lines as $line)
                    <label>{{ $line->description_cn ?: $line->description_en }} · {{ __('portal.returns.return_qty') }}
                        <input type="number" name="quantities[{{ $line->id }}]" min="0" max="{{ $line->qty_shipped ?: $line->carton_qty }}" value="{{ $line->qty_shipped ?: $line->carton_qty }}">
                    </label>
                @endforeach
                <input type="text" name="reason" placeholder="{{ __('portal.returns.reason') }}" required>
                <button type="submit" class="secondary">{{ __('portal.returns.submit') }}</button>
            </form>
        </details>
    @endif

    <h2>{{ __('portal.sections.timeline') }}</h2>
    <ol>
        @foreach ($timeline as $event)
            <li>{{ $event->created_at->format('Y-m-d H:i') }} · <strong>{{ __('orders.customer_statuses.'.\App\Modules\Orders\OrderEnums::CUSTOMER_STATUS_MAP[$event->to_status]) }}</strong></li>
        @endforeach
    </ol>
@endsection
