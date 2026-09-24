@extends('layouts.app')

@section('title', __('warehouse.putaway.title'))

@section('content')
    <h1>{{ __('warehouse.putaway.title') }} · {{ __('warehouse.putaway.pending') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.putaway.hint') }}</small></p>
    {{-- CHANGE_REQUESTS #151: search the pending units (品名 / 唛头 / 预报单号 / 柜号 / 客户 / 单元条码); 全选 then covers the result. --}}
    <form method="get" class="grid" id="putaway-search">
        <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('warehouse.putaway.search') }}" aria-label="{{ __('warehouse.putaway.search') }}" class="scan" autocomplete="off">
        <button type="submit">{{ __('warehouse.putaway.search_go') }}</button>
        @if ($q !== '')<a role="button" class="secondary" href="{{ route('warehouse.putaway.index') }}">{{ __('warehouse.putaway.search_clear') }}</a>@endif
    </form>
    @if ($q !== '')
        <p class="text-muted"><small>{{ __('warehouse.putaway.search_count', ['q' => $q, 'count' => $units->total(), 'page' => $units->count()]) }}</small></p>
    @endif
    @if ($units->isEmpty())
        <p class="text-muted">{{ __($q === '' ? 'warehouse.putaway.empty' : 'warehouse.putaway.search_empty') }}</p>
    @else
        {{-- CHANGE_REQUESTS #149 批量上架: tick units (the checkboxes belong to this form through form="bulk-putaway"), scan ONE location, one click.
             The per-row forms below stay for the odd unit that goes elsewhere. --}}
        <form method="post" action="{{ route('warehouse.putaway.bulk') }}" id="bulk-putaway">
            @csrf
            <article class="kv-card">
                <strong>{{ __('warehouse.putaway.bulk.title') }}</strong>
                <p class="text-muted"><small>{{ __('warehouse.putaway.bulk.hint') }}</small></p>
                <div class="grid">
                    <label>{{ __('warehouse.putaway.bulk.location') }}<input type="text" name="bulk_location_code" class="scan" list="locations-all" placeholder="{{ $units->first()->warehouse->code }}-A-01-01" value="{{ old('bulk_location_code') }}" autocomplete="off"></label>
                    <label>{{ __('warehouse.putaway.bulk.reason') }}<input type="text" name="bulk_tier_reason" maxlength="255" value="{{ old('bulk_tier_reason') }}"></label>
                </div>
                <p style="margin:0">
                    <button type="button" class="secondary outline" id="putaway-select-all" style="padding:.15rem .6rem">{{ __('warehouse.putaway.bulk.select_all') }}</button>
                    <button type="button" class="secondary outline" id="putaway-select-none" style="padding:.15rem .6rem">{{ __('warehouse.putaway.bulk.select_none') }}</button>
                    <button type="submit" id="putaway-bulk-submit" disabled>{{ __('warehouse.putaway.bulk.submit', ['count' => 0]) }}</button>
                </p>
            </article>
        </form>
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th><input type="checkbox" id="putaway-select-page" aria-label="{{ __('warehouse.putaway.bulk.select_all') }}"></th><th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.client') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.stock.unit_type') }}</th><th class="num">{{ __('warehouse.stock.on_hand') }}</th><th>{{ __('warehouse.stock.condition') }}</th><th>{{ __('warehouse.putaway.required_tier') }}</th><th>{{ __('warehouse.stock.location') }}</th><th>{{ __('warehouse.putaway.location_code') }}</th></tr></thead>
            <tbody>
            @foreach ($units as $u)
                {{-- Audit 2026-09-10: a refused row keeps the scanned code (PutawayController flashes it with the unit id) and is outlined like ?highlight=. --}}
                @php($failedHere = (int) old('putaway_unit') === $u->id)
                @php($bottomPallet = $u->unit_type === 'pallet' && $u->required_storage_tier === 'bottom')
                <tr @if ($highlight === $u->id || $failedHere) style="outline:2px solid var(--erp-warn)" @endif>
                    <td><input type="checkbox" name="unit_ids[]" value="{{ $u->id }}" form="bulk-putaway" aria-label="{{ $u->label_code }}" @checked(in_array($u->id, array_map('intval', (array) old('unit_ids', [])), true))></td>
                    <td><code>{{ $u->label_code }}</code></td><td>{{ $u->asnLine->asn->client->name }}</td><td>{{ $u->asnLine->description }}</td><td>{{ __('warehouse.unit_types.'.$u->unit_type) }}</td><td class="num">{{ $u->qty_on_hand }}</td>
                    <td><span class="badge" data-tone="{{ $u->condition === 'good' ? 'ok' : 'danger' }}">{{ __('warehouse.conditions.'.$u->condition) }}</span></td>
                    {{-- CHANGE_REQUESTS #126: the tier declared for the goods; for a bottom pallet the first free bottom location as a hint. --}}
                    <td>
                        <span class="badge" data-tone="{{ $u->required_storage_tier === 'bottom' ? 'warn' : 'muted' }}">{{ __('warehouse.storage_tiers.'.($u->required_storage_tier ?: 'standard')) }}</span>
                        @if ($bottomPallet && isset($bottomHints[$u->warehouse_id]))<br><small>{{ __('warehouse.putaway.bottom_hint', ['code' => $bottomHints[$u->warehouse_id]]) }}</small>@elseif ($bottomPallet)<br><small class="text-muted">{{ __('warehouse.putaway.no_free_bottom') }}</small>@endif
                    </td>
                    <td>{{ $u->location?->full_code }}</td>
                    <td>
                        <form method="post" action="{{ route('warehouse.putaway.store', $u) }}" class="inline">
                            @csrf
                            {{-- #126 review: after a refusal the code field keeps focus with its text selected, so the next scan REPLACES the refused
                                 code; the reason input is optional in the browser (PutawayService refuses a mismatch without one) and never takes focus. --}}
                            <input type="text" name="location_code" class="scan" list="locations-{{ $u->warehouse_id }}" placeholder="{{ $u->warehouse->code }}-A-01-01" value="{{ $failedHere ? old('location_code') : '' }}" required style="width:12rem" @if ($failedHere) autofocus onfocus="this.select()" @endif>
                            @if ($failedHere && old('tier_refused'))
                                <input type="text" name="tier_reason" maxlength="255" placeholder="{{ __('warehouse.putaway.tier_reason') }}" style="width:14rem">
                            @endif
                            <button type="submit">{{ __('warehouse.putaway.do') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @foreach ($locations as $warehouseId => $list)
            <datalist id="locations-{{ $warehouseId }}">@foreach ($list as $loc)<option value="{{ $loc->full_code }}">{{ __('warehouse.location_types.'.$loc->type) }}</option>@endforeach</datalist>
        @endforeach
        <datalist id="locations-all">@foreach ($locations as $list)@foreach ($list as $loc)<option value="{{ $loc->full_code }}">{{ __('warehouse.location_types.'.$loc->type) }}</option>@endforeach @endforeach</datalist>
        {{ $units->links() }}
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        // CHANGE_REQUESTS #149: 全选 / 取消 and the live count on the 批量上架 button; the button only enables with a tick and a code.
        const boxes = () => Array.from(document.querySelectorAll('input[name="unit_ids[]"][form="bulk-putaway"]'));
        const page = document.getElementById('putaway-select-page');
        const submit = document.getElementById('putaway-bulk-submit');
        const code = document.querySelector('#bulk-putaway input[name="bulk_location_code"]');
        const template = submit ? submit.textContent : '';
        const sync = () => {
            const ticked = boxes().filter(b => b.checked).length;
            if (submit) { submit.textContent = template.replace(/\d+/, ticked); submit.disabled = ticked === 0 || !(code && code.value.trim()); }
            if (page) { page.checked = ticked > 0 && ticked === boxes().length; page.indeterminate = ticked > 0 && ticked < boxes().length; }
        };
        const setAll = (on) => { boxes().forEach(b => { b.checked = on; }); sync(); };
        document.getElementById('putaway-select-all')?.addEventListener('click', () => setAll(true));
        document.getElementById('putaway-select-none')?.addEventListener('click', () => setAll(false));
        page?.addEventListener('change', () => setAll(page.checked));
        boxes().forEach(b => b.addEventListener('change', sync));
        code?.addEventListener('input', sync);
        sync();
    })();
</script>
@endpush
