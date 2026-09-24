@extends('layouts.app')

@section('title', __('warehouse.receiving.bulk.title'))

@section('content')
    <p><a href="{{ route('warehouse.asns.show', $asn) }}">← {{ $asn->asn_no }}</a></p>
    <h1>{{ __('warehouse.receiving.bulk.title') }}</h1>
    <p class="text-muted">{{ $asn->client->name }} · {{ $asn->warehouse->code }} · {{ __('warehouse.asns.asn_no') }} {{ $asn->asn_no }}</p>
    <p><mark>{{ __($receipt['open'] ? 'warehouse.receiving.joins_receipt' : 'warehouse.receiving.new_receipt', ['no' => $receipt['no']]) }}</mark></p>
    <p class="text-muted"><small>{{ __('warehouse.receiving.bulk.hint') }}</small></p>

    @if ($errors->any())
        <article><ul style="margin:0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    @if (! $receivable)
        <p>{{ __('warehouse.receiving.bulk.not_receivable') }}</p>
    @elseif ($lines->isEmpty())
        <p>{{ __('warehouse.receiving.bulk.nothing') }}</p>
    @elseif ($receivingLocations->isEmpty())
        {{-- Audit 2026-09-10: no active 收货区 in this warehouse — the form could never be submitted; point at 库位配置 instead. --}}
        <p><mark>{{ __('warehouse.receiving.no_receiving_location', ['code' => $asn->warehouse->code]) }}</mark> <a href="{{ route('warehouse.locations.index') }}">{{ __('warehouse.locations.title') }}</a></p>
    @else
        <form method="post" action="{{ route('warehouse.receiving.bulk_store', $asn) }}" id="bulk-receive" onsubmit="this.querySelector('button[type=submit]').disabled = true">
            @csrf
            <div class="grid">
                <label>{{ __('warehouse.receiving.location') }}
                    <select name="receiving_location_id" required>
                        @foreach ($receivingLocations as $loc)<option value="{{ $loc->id }}" @selected((int) old('receiving_location_id') === $loc->id)>{{ $loc->full_code }}</option>@endforeach
                    </select>
                </label>
                <label>{{ __('warehouse.receiving.bulk.delivery_reference') }}<input type="text" name="delivery_reference" value="{{ old('delivery_reference') }}" maxlength="100"></label>
            </div>
            <p><button type="button" class="secondary outline" id="select-all" style="padding:.15rem .6rem">{{ __('warehouse.receiving.bulk.select_all') }}</button> <button type="button" class="secondary outline" id="select-none" style="padding:.15rem .6rem">{{ __('warehouse.receiving.bulk.select_none') }}</button></p>
            {{-- Audit 2026-09-22 INBOUND-05 (CR #141): 全部设为 — one click sets every row's unit type / 托盘来源 (pallet rental / purchase bill from the source). --}}
            <div class="grid" id="set-all">
                <label>{{ __('warehouse.receiving.set_all') }}
                    <select id="set-all-type" aria-label="{{ __('warehouse.receiving.unit_type') }}"><option value="">{{ __('warehouse.receiving.unit_type') }}: —</option>@foreach ($unitTypes as $t)<option value="{{ $t }}">{{ __('warehouse.unit_types.'.$t) }}</option>@endforeach</select>
                </label>
                <label>&nbsp;<select id="set-all-source" aria-label="{{ __('warehouse.receiving.pallet_source') }}"><option value="">{{ __('warehouse.receiving.pallet_source') }}: —</option>@foreach ($palletSources as $ps)<option value="{{ $ps }}">{{ __('warehouse.pallet_sources.'.$ps) }}</option>@endforeach</select></label>
            </div>
            <div class="overflow-auto"><table class="dense form-rows">
                <thead><tr>
                    <th>{{ __('warehouse.receiving.bulk.include') }}</th><th>#</th><th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.asns.container_no') }}</th>
                    <th class="num">{{ __('warehouse.asns.expected') }}</th><th class="num">{{ __('warehouse.receiving.received_cartons') }}</th><th class="num">{{ __('warehouse.receiving.damaged_cartons') }}</th>
                    <th>{{ __('warehouse.receiving.unit_type') }}</th><th class="num">{{ __('warehouse.receiving.bulk.unit_count') }}</th><th>{{ __('warehouse.receiving.pallet_source') }}</th><th class="num">{{ __('warehouse.receiving.bulk.weight_total') }}</th><th>{{ __('warehouse.receiving.bulk.variance_reason') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($lines as $i => $l)
                    @php($old = old("rows.$i", []))
                    <tr>
                        <td><input type="checkbox" name="rows[{{ $i }}][include]" value="1" @checked(old('rows') === null || ($old['include'] ?? false))><input type="hidden" name="rows[{{ $i }}][asn_line_id]" value="{{ $l->id }}"></td>
                        <td>{{ $l->id }}</td><td>{{ $l->consignment_mark }}</td><td>{{ $l->description }}</td><td>{{ $l->container?->container_no }}</td>
                        <td class="num">{{ $l->expected_cartons }}</td>
                        <td class="num"><input type="number" name="rows[{{ $i }}][received_cartons]" min="0" value="{{ $old['received_cartons'] ?? $l->expected_cartons }}" style="width:5.5rem"></td>
                        <td class="num"><input type="number" name="rows[{{ $i }}][damaged_cartons]" min="0" value="{{ $old['damaged_cartons'] ?? 0 }}" style="width:5rem"></td>
                        <td><select name="rows[{{ $i }}][unit_type]" style="width:auto">@foreach ($unitTypes as $t)<option value="{{ $t }}" @selected(($old['unit_type'] ?? $defaultUnitType) === $t)>{{ __('warehouse.unit_types.'.$t) }}</option>@endforeach</select></td>
                        <td class="num"><input type="number" name="rows[{{ $i }}][unit_count]" min="1" max="500" value="{{ $old['unit_count'] ?? 1 }}" style="width:5rem"></td>
                        <td><select name="rows[{{ $i }}][pallet_source]" class="pallet-source" style="width:auto" aria-label="{{ __('warehouse.receiving.pallet_source') }}">@foreach ($palletSources as $ps)<option value="{{ $ps }}" @selected(($old['pallet_source'] ?? 'client_own') === $ps)>{{ __('warehouse.pallet_sources.'.$ps) }}</option>@endforeach</select></td>
                        <td class="num"><input type="number" name="rows[{{ $i }}][weight_kg]" min="0" step="0.001" value="{{ $old['weight_kg'] ?? '' }}" placeholder="kg" style="width:6rem"></td>
                        <td><input type="text" name="rows[{{ $i }}][variance_reason]" value="{{ $old['variance_reason'] ?? '' }}" maxlength="255" style="min-width:12rem"></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <label><input type="checkbox" name="complete" value="1" @checked(old('rows') === null || old('complete'))> {{ __('warehouse.receiving.bulk.complete_now') }}</label>
            <button type="submit">{{ __('warehouse.receiving.bulk.submit') }}</button>
        </form>
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        const boxes = () => document.querySelectorAll('#bulk-receive input[type=checkbox][name$="[include]"]');
        document.getElementById('select-all')?.addEventListener('click', () => boxes().forEach(b => { b.checked = true; }));
        document.getElementById('select-none')?.addEventListener('click', () => boxes().forEach(b => { b.checked = false; }));
        // 全部设为: the chosen unit type / 托盘来源 goes onto every row; the row selects stay editable afterwards.
        const setAll = (pickerId, selector) => document.getElementById(pickerId)?.addEventListener('change', event => {
            if (!event.target.value) return;
            document.querySelectorAll(selector).forEach(select => { select.value = event.target.value; });
            event.target.value = '';
        });
        setAll('set-all-type', '#bulk-receive select[name$="[unit_type]"]');
        setAll('set-all-source', '#bulk-receive select.pallet-source');
        // CHANGE_REQUESTS #148: a big ASN (286 lines × 9 inputs) exceeds PHP's max_input_vars (1000) and the server would silently drop
        // every row past ~110. On submit the rows are packed into ONE field (rows_json — unticked rows left out, as a browser would) and
        // the row inputs are disabled so they are not posted at all; bulkStore() unpacks the field and validates exactly as before.
        const form = document.getElementById('bulk-receive');
        const rowInputs = () => form ? form.querySelectorAll('[name^="rows["]') : [];
        form?.addEventListener('submit', () => {
            const rows = {};
            rowInputs().forEach(el => {
                const m = el.name.match(/^rows\[(\d+)\]\[(\w+)\]$/);
                if (!m || (el.type === 'checkbox' && !el.checked)) return;
                (rows[m[1]] ||= {})[m[2]] = el.value;
            });
            const packed = document.createElement('input');
            packed.type = 'hidden'; packed.name = 'rows_json'; packed.value = JSON.stringify(rows);
            form.appendChild(packed);
            rowInputs().forEach(el => { el.disabled = true; });
        });
        // Coming back through the browser's cache must not leave the rows disabled.
        window.addEventListener('pageshow', () => { rowInputs().forEach(el => { el.disabled = false; }); form?.querySelector('input[name="rows_json"]')?.remove(); });
    })();
</script>
@endpush
