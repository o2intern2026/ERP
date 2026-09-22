@extends('layouts.app')

@section('title', __('warehouse.receiving.title'))

@section('content')
    <p><a href="{{ route('warehouse.asns.show', $asn) }}">← {{ $asn->asn_no }}</a></p>
    <h1>{{ __('warehouse.receiving.title') }} · {{ __('warehouse.receiving.line') }} #{{ $line->id }}</h1>
    <p>{{ $asn->client->name }} · {{ $line->consignment_mark }} · <strong>{{ $line->description }}</strong> · {{ __('warehouse.asns.expected') }}: {{ $line->expected_cartons }}</p>
    <p><mark>{{ __($receipt['open'] ? 'warehouse.receiving.joins_receipt' : 'warehouse.receiving.new_receipt', ['no' => $receipt['no']]) }}</mark></p>
    <p class="text-muted"><small>{{ __('warehouse.receiving.hint') }}</small></p>
    @if ($receivingLocations->isEmpty())
        {{-- Audit 2026-09-10: a required <select> with no options blocks the submit silently — say what is missing and where to fix it. --}}
        <p><mark>{{ __('warehouse.receiving.no_receiving_location', ['code' => $asn->warehouse->code]) }}</mark> <a href="{{ route('warehouse.locations.index') }}">{{ __('warehouse.locations.title') }}</a></p>
    @else
    {{-- Audit 2026-09-22 INBOUND-02: the button locks on submit so a slow tablet's double tap cannot post the line twice (the service refuses the second anyway). --}}
    <form method="post" action="{{ route('warehouse.receiving.store', [$asn, $line]) }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.receiving.location') }}<select name="receiving_location_id" required>@foreach ($receivingLocations as $loc)<option value="{{ $loc->id }}" @selected((int) old('receiving_location_id') === $loc->id)>{{ $loc->full_code }}</option>@endforeach</select></label>
            <label>{{ __('warehouse.receiving.received_cartons') }}<input type="number" name="received_cartons" class="scan" min="0" value="{{ old('received_cartons', $line->expected_cartons) }}" required autofocus></label>
            <label>{{ __('warehouse.receiving.damaged_cartons') }}<input type="number" name="damaged_cartons" min="0" value="{{ old('damaged_cartons', 0) }}"></label>
            @if ($asn->inbound_type === 'loose_truck')
                <label>{{ __('warehouse.receiving.unloaded_pallets') }}<input type="number" name="unloaded_pallets" min="0" value="{{ old('unloaded_pallets') }}"></label>
            @endif
        </div>
        <label>{{ __('warehouse.receiving.variance_reason') }}<input type="text" name="variance_reason" value="{{ old('variance_reason') }}"></label>
        <fieldset>
            <legend>{{ __('warehouse.receiving.units_title') }}</legend>
            @error('units')<p><mark>{{ $message }}</mark></p>@enderror
            {{-- Audit 2026-09-22 INBOUND-04 / INBOUND-05 (CR #141): rows come from a <template> (no cap of three); with ONE row its 箱数 mirrors 实收箱数,
                 with several rows the running total is shown and the submit locks until Σ 箱数 = 实收. The server checks the same sum. --}}
            @php($unitRows = array_values(array_filter((array) old('units', []), 'is_array')) ?: [['unit_type' => 'pallet', 'carton_qty' => $line->expected_cartons]])
            <div id="units">
                @foreach ($unitRows as $index => $row)
                    @include('warehouse::receiving._unit-row', ['index' => $index, 'row' => $row, 'palletSources' => $palletSources, 'palletClasses' => $palletClasses])
                @endforeach
            </div>
            <template id="unit-row-template">@include('warehouse::receiving._unit-row', ['index' => '__INDEX__', 'row' => ['unit_type' => 'pallet'], 'palletSources' => $palletSources, 'palletClasses' => $palletClasses])</template>
            <p><button type="button" class="secondary outline" id="add-unit" style="width:auto">{{ __('warehouse.receiving.add_unit') }}</button>
                <small id="unit-total" class="text-muted" hidden></small></p>
        </fieldset>
        <button type="submit">{{ __('warehouse.receiving.submit') }}</button>
    </form>
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        const form = document.querySelector('form[action*="receive"]');
        if (!form) return;
        const received = form.querySelector('input[name="received_cartons"]');
        const body = document.getElementById('units');
        const template = document.getElementById('unit-row-template');
        const total = document.getElementById('unit-total');
        const submit = form.querySelector('button[type=submit]');
        const rows = () => Array.from(body.querySelectorAll('.unit-row'));
        const qtyInputs = () => rows().map(row => row.querySelector('.unit-qty'));
        const sum = () => qtyInputs().reduce((acc, input) => acc + (Number(input.value) || 0), 0);
        // One row: its 箱数 follows 实收箱数 (the audit's "入库单 says 95, stock says 100"). Several rows: show Σ / 实收 and lock the submit on a mismatch.
        const reconcile = () => {
            const wanted = Number(received.value) || 0;
            if (rows().length === 1) {
                qtyInputs()[0].value = wanted > 0 ? String(wanted) : '';
                total.hidden = true;
                submit.disabled = false;
                return;
            }
            const current = sum();
            total.hidden = false;
            total.textContent = @json(__('warehouse.receiving.unit_total')).replace(':units', String(current)).replace(':received', String(wanted));
            const ok = wanted === 0 || current === wanted;
            total.style.color = ok ? '' : 'var(--erp-danger)';
            submit.disabled = !ok;
        };
        const next = () => Math.max(-1, ...rows().map(row => Number(row.dataset.index) || 0)) + 1;
        document.getElementById('add-unit').addEventListener('click', () => {
            body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next())));
            body.lastElementChild.querySelector('.unit-qty')?.focus();
            reconcile();
        });
        body.addEventListener('click', event => {
            const button = event.target.closest('button.remove-row');
            if (!button) return;
            if (rows().length > 1) button.closest('.unit-row').remove();
            reconcile();
        });
        received.addEventListener('input', reconcile);
        body.addEventListener('input', event => { if (event.target.classList.contains('unit-qty')) reconcile(); });
        // Only the submit click re-checks; the lock on submit (double tap) stays in the form's onsubmit.
        reconcile();
    })();
</script>
@endpush
