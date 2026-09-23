@extends('layouts.app')

@section('title', __('portal.inbound.manual.title'))

@section('content')
    <p><a href="{{ route('portal.asns.index') }}">← {{ __('portal.asns.title') }}</a> · <a href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.list_title') }}</a> · <a href="{{ route('portal.asns.imports.create') }}">{{ __('portal.inbound.upload_button') }}</a></p>
    <header>
        <h1>{{ __('portal.inbound.manual.title') }}</h1>
        <p class="text-muted"><small>{{ __('portal.inbound.manual.hint') }}</small></p>
    </header>

    @if (session('status'))<article>{{ session('status') }}</article>@endif
    @if ($draft)
        <p><span class="badge" data-tone="muted">{{ __('portal.inbound.statuses.draft') }}</span> <small class="text-muted">{{ __('portal.inbound.manual.draft_note', ['id' => $draft->id, 'time' => $draft->updated_at?->format('Y-m-d H:i')]) }}</small></p>
    @endif
    @if ($errors->any())
        <article role="alert"><strong>{{ __('portal.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    {{-- CHANGE_REQUESTS #128: rows typed on the page + existing orders ticked (以订单为准) + inbound context + 到仓方式; action = draft | preview. --}}
    <form method="post" action="{{ route('portal.asns.imports.manual.store') }}" id="manual-form">
        @csrf
        @if ($draft)<input type="hidden" name="draft_id" value="{{ $draft->id }}">@endif

        <article class="kv-card">
            <strong>{{ __('portal.inbound.manual.sections.rows') }}</strong>
            @include('portal::asns.imports.partials.manual-rows')
        </article>

        <article class="kv-card" id="attach-orders">
            <strong>{{ __('portal.inbound.manual.sections.attach') }}</strong>
            <p class="text-muted"><small>{{ __('portal.inbound.manual.attach_hint') }}</small></p>
            @if ($attachable->isEmpty())
                <p class="text-muted"><small>{{ __('portal.inbound.manual.attach_empty') }}</small></p>
            @else
                <div class="overflow-auto"><table class="dense">
                    <thead><tr>
                        <th>{{ __('portal.inbound.manual.attach_columns.select') }}</th><th>{{ __('portal.inbound.manual.attach_columns.order_no') }}</th><th>{{ __('portal.inbound.manual.attach_columns.mark') }}</th>
                        <th>{{ __('portal.inbound.manual.attach_columns.consignee') }}</th><th>{{ __('portal.inbound.manual.attach_columns.destination') }}</th>
                        <th>{{ __('portal.inbound.manual.attach_columns.requested_date') }}</th><th class="num">{{ __('portal.inbound.manual.attach_columns.cartons') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($attachable as $order)
                        <tr>
                            <td><input type="checkbox" name="attached_order_ids[]" value="{{ $order->id }}" aria-label="{{ $order->order_no }}" @checked(in_array((int) $order->id, $attachedIds, true))></td>
                            <td><a href="{{ route('portal.orders.show', $order) }}">{{ $order->order_no }}</a></td>
                            <td>{{ $order->consignment_mark ?: '—' }}</td>
                            <td>{{ $order->deliver_to_name }}</td>
                            <td>{{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }}</td>
                            <td>{{ $order->requested_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="num">{{ (int) $order->lines->whereNull('asn_line_id')->sum('carton_qty') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </article>

        @include('portal::asns.imports.partials.context-fields')
        @include('portal::asns.imports.partials.import-options'){{-- CHANGE_REQUESTS #143 --}}
        @include('portal::asns.imports.partials.collection-fields')

        <button type="submit" name="action" value="preview">{{ __('portal.inbound.manual.actions.preview') }}</button>
        <button type="submit" name="action" value="draft" class="secondary">{{ __('portal.inbound.manual.actions.save_draft') }}</button>
        <a class="secondary" role="button" href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.manual.actions.back') }}</a>
    </form>
@endsection
