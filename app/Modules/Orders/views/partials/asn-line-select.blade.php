{{-- A12 + 2026-09-10 audit: the ASN goods-line picker of one draft line. Always rendered on a received from_stock order — when the client
     has no ASN lines yet the select is empty and the hint says what to do, so the 确认 refusal is never a dead end. Searchable via the
     filter box (inline JS in orders::show). The filter is NOT a form control (form="asn-filter-none" points at no form), so pressing
     Enter in it never submits the surrounding line form (tester feedback 2026-09-10). $asnLineOptions: rows of asn_lines ⨝ asns for the
     order's client; $selected: current asn_line_id. --}}
@php($selected = $selected ?? null)
<fieldset class="asn-line-picker" style="margin:.5rem 0 0;padding:.6rem .9rem .5rem;border:1px solid var(--pico-muted-border-color);border-radius:var(--pico-border-radius)">
    <legend style="padding:0 .3rem;font-size:.9rem;font-weight:600">{{ __('orders.drafts.asn_picker_title') }}</legend>
    <div class="grid" style="margin-bottom:.25rem">
        <input type="search" data-asn-filter form="asn-filter-none" placeholder="{{ __('orders.drafts.asn_filter_placeholder') }}" aria-label="{{ __('orders.drafts.asn_filter_placeholder') }}" autocomplete="off" style="margin-bottom:0">
        <select name="asn_line_id" aria-label="{{ __('orders.fulfilments.fields.asn_line') }}" style="margin-bottom:0">
            <option value="">{{ __('orders.drafts.no_asn_line') }}</option>
            @foreach ($asnLineOptions as $option)
                <option value="{{ $option->id }}" @selected((int) $selected === (int) $option->id)>{{ $option->asn_no }} · {{ $option->consignment_mark }} · {{ $option->description }} ({{ __('orders.drafts.asn_option_cartons', ['count' => $option->received_cartons ?? $option->expected_cartons]) }})</option>
            @endforeach
        </select>
    </div>
    <small class="text-muted"><span data-asn-count></span>{{ $asnLineOptions->isEmpty() ? __('orders.drafts.no_asn_lines_yet') : __('orders.drafts.asn_link_hint') }}</small>
</fieldset>
