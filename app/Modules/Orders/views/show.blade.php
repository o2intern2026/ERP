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
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

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
