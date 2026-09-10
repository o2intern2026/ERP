{{-- A12 + 2026-09-10 audit: the ASN goods-line picker of one draft line. Always rendered on a received from_stock order — when the client
     has no ASN lines yet the select is empty and the hint says what to do, so the 确认 refusal is never a dead end. Searchable via the
     filter box (inline JS in orders::show). $asnLineOptions: rows of asn_lines ⨝ asns for the order's client; $selected: current asn_line_id. --}}
@php($selected = $selected ?? null)
<div class="asn-line-picker">
    <input type="search" data-asn-filter placeholder="{{ __('orders.drafts.asn_filter_placeholder') }}" aria-label="{{ __('orders.drafts.asn_filter_placeholder') }}" autocomplete="off">
    <select name="asn_line_id" aria-label="{{ __('orders.fulfilments.fields.asn_line') }}">
        <option value="">{{ __('orders.drafts.no_asn_line') }}</option>
        @foreach ($asnLineOptions as $option)
            <option value="{{ $option->id }}" @selected((int) $selected === (int) $option->id)>{{ $option->asn_no }} · {{ $option->consignment_mark }} · {{ $option->description }} ({{ $option->received_cartons ?? $option->expected_cartons }})</option>
        @endforeach
    </select>
    <small class="text-muted">{{ $asnLineOptions->isEmpty() ? __('orders.drafts.no_asn_lines_yet') : __('orders.drafts.asn_link_hint') }}</small>
</div>
