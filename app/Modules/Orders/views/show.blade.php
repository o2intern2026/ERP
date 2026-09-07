@extends('layouts.app')

@section('title', $order->order_no)

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.actions.back') }}</a></p>
    <header>
        <h1>{{ $order->order_no }}</h1>
        <p>{{ $order->client->name }} · <a href="{{ route('platform.jobs.show', $order->job) }}">{{ $order->job->job_no }}</a></p>
    </header>

    <div class="grid">
        <article>
            <header>{{ __('orders.fields.operational_status') }}</header>
            <strong>{{ __('orders.statuses.operational.'.$order->operational_status) }}</strong>
            <small>{{ __('orders.fields.customer_status') }}: {{ __('orders.customer_statuses.'.$order->customerStatus()) }}</small>
        </article>
        <article>
            <header>{{ __('orders.fields.fulfilment_status') }}</header>
            <strong>{{ __('orders.statuses.fulfilment.'.$order->fulfilment_status) }}</strong>
        </article>
        <article>
            <header>{{ __('orders.fields.billing_status') }}</header>
            <strong>{{ __('orders.statuses.billing.'.$order->billing_status) }}</strong>
        </article>
    </div>

    @if ($order->operational_status === 'received')
        <form method="post" action="{{ route('orders.confirm', $order) }}">
            @csrf
            <button type="submit">{{ __('orders.actions.confirm') }}</button>
        </form>
    @endif

    <h2>{{ __('orders.sections.instruction') }}</h2>
    <dl>
        <dt>{{ __('orders.fields.order_type') }}</dt><dd>{{ __('orders.types.'.$order->order_type) }}</dd>
        <dt>{{ __('orders.fields.source') }}</dt><dd>{{ __('orders.sources.'.$order->source) }}</dd>
        <dt>{{ __('orders.fields.job_reference') }}</dt><dd>{{ $order->job->reference ?: __('orders.not_provided') }}</dd>
        <dt>{{ __('orders.fields.external_ref') }}</dt><dd>{{ $order->external_ref ?: __('orders.not_provided') }}</dd>
        <dt>{{ __('orders.fields.consignment_mark') }}</dt><dd>{{ $order->consignment_mark ?: __('orders.not_provided') }}</dd>
        <dt>{{ __('orders.fields.fba_reference') }}</dt><dd>{{ $order->fba_reference ?: __('orders.not_provided') }}</dd>
        <dt>{{ __('orders.fields.requested_date') }}</dt><dd>{{ $order->requested_date->format('Y-m-d') }}</dd>
        <dt>{{ __('orders.fields.service_level') }}</dt><dd>{{ __('orders.service_levels.'.$order->service_level) }}</dd>
    </dl>

    <h2>{{ __('orders.sections.delivery') }}</h2>
    <p><strong>{{ __('orders.fields.delivery_instructions') }}:</strong> {{ $order->delivery_instructions ?: __('orders.not_provided') }}</p>
    <p>{{ $order->deliver_to_name }}@if ($order->deliver_to_phone) · {{ $order->deliver_to_phone }}@endif<br>
        {{ $order->deliver_to_address }}, {{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }} {{ $order->deliver_to_postcode }}
    </p>

    @if ($order->isEditable())
        <details>
            <summary>{{ __('orders.actions.edit_delivery') }}</summary>
            <form method="post" action="{{ route('orders.update', $order) }}">
                @csrf
                @method('PATCH')
                <div class="grid">
                    <label>{{ __('orders.fields.deliver_to_name') }}<input name="deliver_to_name" value="{{ $order->deliver_to_name }}" required></label>
                    <label>{{ __('orders.fields.deliver_to_phone') }}<input name="deliver_to_phone" value="{{ $order->deliver_to_phone }}"></label>
                </div>
                <label>{{ __('orders.fields.address') }}<input name="deliver_to_address" value="{{ $order->deliver_to_address }}" required></label>
                <div class="grid">
                    <label>{{ __('orders.fields.suburb') }}<input name="deliver_to_suburb" value="{{ $order->deliver_to_suburb }}" required></label>
                    <label>{{ __('orders.fields.state') }}<input name="deliver_to_state" value="{{ $order->deliver_to_state }}" required></label>
                    <label>{{ __('orders.fields.postcode') }}<input name="deliver_to_postcode" value="{{ $order->deliver_to_postcode }}" required></label>
                    <label>{{ __('orders.fields.requested_date') }}<input type="date" name="requested_date" value="{{ $order->requested_date->format('Y-m-d') }}" required></label>
                </div>
                <label>{{ __('orders.fields.delivery_instructions') }}
                    <textarea name="delivery_instructions" rows="3">{{ $order->delivery_instructions }}</textarea>
                </label>
                <button type="submit">{{ __('orders.actions.save_changes') }}</button>
            </form>
        </details>
    @else
        <p><strong>{{ __('orders.messages.locked') }}</strong></p>
    @endif

    @if ($order->order_type === 'pickup_deliver')
        <h2>{{ __('orders.pickup.title') }}</h2>
        <p class="text-muted"><small>{{ __('orders.pickup.hint') }}</small></p>
        @if ($order->pickup_address)
            <p>{{ $order->pickup_address['name'] ?? '' }}@if ($order->pickup_address['phone'] ?? null) · {{ $order->pickup_address['phone'] }}@endif<br>
                {{ $order->pickup_address['address'] ?? '' }}, {{ $order->pickup_address['suburb'] ?? '' }} {{ $order->pickup_address['state'] ?? '' }} {{ $order->pickup_address['postcode'] ?? '' }}</p>
        @endif
        <h3>{{ __('orders.pickup.packages_title') }}</h3>
        @if ($order->declaredPackages->isEmpty())
            <p class="text-muted">{{ __('orders.pickup.none') }}</p>
        @else
            <table class="dense">
                <thead><tr><th>{{ __('orders.pickup.package_type') }}</th><th>{{ __('orders.pickup.qty') }}</th><th>{{ __('orders.pickup.weight_kg') }}</th><th>{{ __('orders.pickup.dims') }}</th></tr></thead>
                <tbody>
                    @foreach ($order->declaredPackages as $package)
                        <tr><td>{{ $package->package_type }}</td><td>{{ $package->qty }}</td><td>{{ $package->weight_kg ?? __('orders.not_provided') }}</td><td>{{ $package->length_mm && $package->width_mm && $package->height_mm ? $package->length_mm.' × '.$package->width_mm.' × '.$package->height_mm : __('orders.not_provided') }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    <div class="grid">
        <article>
            <header>{{ __('orders.tailgate.title') }}</header>
            <strong>{{ $order->tailgate_required ? __('orders.tailgate.required') : __('orders.tailgate.not_required') }}</strong>
            @if ($order->tailgate_reason)
                <span class="badge" data-tone="{{ $order->tailgate_reason === 'manual' ? 'warn' : 'muted' }}">{{ __('orders.tailgate.reasons.'.$order->tailgate_reason) }}</span>
            @endif
            <p class="text-muted"><small>{{ __('orders.tailgate.rule', ['threshold' => $tailgate['threshold_kg'], 'heaviest' => number_format($tailgate['heaviest_piece_kg'], 2)]) }}</small></p>
            @if (! in_array($order->operational_status, ['dispatched', 'delivered', 'returned', 'cancelled'], true) && auth()->user()->hasAnyRole(['admin', 'customer_service', 'dispatcher']))
                <details>
                    <summary>{{ __('orders.tailgate.override') }}</summary>
                    <form method="post" action="{{ route('orders.tailgate', $order) }}">
                        @csrf
                        <label><input type="hidden" name="tailgate_required" value="0"><input type="checkbox" name="tailgate_required" value="1" @checked($order->tailgate_required)> {{ __('orders.tailgate.required') }}</label>
                        <input type="text" name="reason" placeholder="{{ __('orders.tailgate.reason') }}" required>
                        <button type="submit" class="secondary">{{ __('orders.tailgate.save') }}</button>
                    </form>
                </details>
            @endif
        </article>
        <article>
            <header>{{ __('orders.holds.title') }}</header>
            @forelse ($holds as $hold)
                <p>
                    <span class="badge" data-tone="{{ $hold->hold_type === 'financial' ? 'danger' : 'warn' }}">{{ __('orders.holds.types.'.$hold->hold_type) }}</span>
                    @if ($hold->order_id === null)<small class="text-muted">{{ __('orders.holds.client_wide') }}</small>@endif
                    {{ $hold->message }} <small class="text-muted">{{ __('orders.holds.since') }} {{ \Carbon\Carbon::parse($hold->created_at)->format('m-d H:i') }}</small>
                    @if ($hold->order_id !== null && auth()->user()->hasAnyRole($hold->hold_type === 'financial' ? ['admin', 'finance'] : ['admin', 'customer_service', 'dispatcher', 'finance']))
                        <form method="post" action="{{ route('orders.holds.release', [$order, $hold->id]) }}" class="inline">
                            @csrf
                            <input type="text" name="note" placeholder="{{ __('orders.holds.release_note') }}" required style="width:12rem">
                            <button type="submit" class="secondary outline">{{ __('orders.holds.release') }}</button>
                        </form>
                    @endif
                </p>
            @empty
                <p class="text-muted">{{ __('orders.holds.none') }}</p>
            @endforelse
            @if (! in_array($order->operational_status, ['delivered', 'returned', 'cancelled'], true) && auth()->user()->hasAnyRole(['admin', 'customer_service', 'dispatcher', 'finance']))
                <form method="post" action="{{ route('orders.holds.store', $order) }}" class="grid">
                    @csrf
                    <select name="hold_type">
                        @foreach ($holdTypes as $type)
                            <option value="{{ $type }}" @disabled($type === 'financial' && ! auth()->user()->hasAnyRole(['admin', 'finance']))>{{ __('orders.holds.types.'.$type) }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="reason" placeholder="{{ __('orders.holds.reason') }}" required>
                    <button type="submit" class="secondary">{{ __('orders.holds.place') }}</button>
                </form>
                <p class="text-muted"><small>{{ __('orders.holds.financial_hint') }}</small></p>
            @endif
        </article>
    </div>

    <h2>{{ __('orders.sections.goods') }}</h2>
    <div class="overflow-auto">
        <table>
            <thead><tr>
                <th>{{ __('orders.fields.description') }}</th>
                <th>{{ __('orders.fields.package_type') }}</th>
                <th>{{ __('orders.fields.carton_qty') }}</th>
                <th>{{ __('orders.fields.unit_qty') }}</th>
                <th>{{ __('orders.fields.weight_kg') }}</th>
                <th>{{ __('orders.fields.dimensions') }}</th>
                <th>{{ __('orders.batches.asn_ref') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($order->lines as $line)
                    <tr>
                        <td>{{ $line->description_cn ?: $line->description_en }}</td>
                        <td>{{ $line->package_type }}</td>
                        <td>{{ $line->carton_qty }}</td>
                        <td>{{ $line->unit_qty ?? __('orders.not_provided') }}</td>
                        <td>{{ $line->actual_weight_kg ?? __('orders.not_provided') }}</td>
                        <td>{{ $line->length_mm && $line->width_mm && $line->height_mm ? $line->length_mm.' × '.$line->width_mm.' × '.$line->height_mm : __('orders.not_provided') }}</td>
                        <td>
                            @if ($line->asn_line_id && isset($asnRefs[$line->asn_line_id]))
                                <a href="{{ route('orders.batches', ['ref' => $asnRefs[$line->asn_line_id]->asn_no]) }}">{{ $asnRefs[$line->asn_line_id]->asn_no }}</a>
                                @if ($asnRefs[$line->asn_line_id]->container_no) <small class="text-muted">{{ $asnRefs[$line->asn_line_id]->container_no }}</small>@endif
                            @else
                                <span class="text-muted">{{ __('orders.batches.unlinked') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($order->order_type === 'from_stock')
        <h2>{{ __('orders.fulfilments.availability_title') }}</h2>
        @include('orders::fulfilments.partials.availability')
    @endif

    <h2>{{ __('orders.fulfilments.batches_title') }}</h2>
    <p><a href="{{ route('orders.fulfilments.index', $order) }}">{{ __('orders.fulfilments.actions.open') }}</a></p>
    @include('orders::fulfilments.partials.batches')

    <h2>{{ __('orders.timeline.title') }}</h2>
    <ol>
        @foreach ($order->events as $event)
            <li>
                <strong>{{ __('orders.dimensions.'.$event->dimension) }}: {{ __('orders.statuses.'.$event->dimension.'.'.$event->to_status) }}</strong>
                <small>{{ $event->created_at->format('Y-m-d H:i') }} · {{ $event->actor?->name ?? __('orders.timeline.system') }}</small>
                @if ($event->note)<p>{{ $event->note }}</p>@endif
            </li>
        @endforeach
    </ol>
@endsection
