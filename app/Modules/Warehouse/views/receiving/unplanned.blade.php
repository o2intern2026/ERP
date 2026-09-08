@extends('layouts.app')

@section('title', __('warehouse.receiving.unplanned.title'))

@section('content')
    <p><a href="{{ route('warehouse.receiving.index') }}">← {{ __('warehouse.receiving.worklist') }}</a></p>
    <h1>{{ __('warehouse.receiving.unplanned.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.receiving.unplanned.hint') }}</small></p>
    <form method="post" action="{{ route('warehouse.receiving.unplanned.store') }}" id="unplanned-form">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.receiving.unplanned.client') }}
                <select name="client_id" required>
                    <option value="">—</option>
                    @foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id') === $c->id)>{{ $c->code }} · {{ $c->name }}</option>@endforeach
                </select>
            </label>
            <label>{{ __('warehouse.receiving.unplanned.warehouse') }}
                <select name="warehouse_id" id="warehouse-select" required>
                    @foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('warehouse_id', $currentWarehouseId ?? $warehouses->first()?->id) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach
                </select>
            </label>
            <label>{{ __('warehouse.receiving.unplanned.inbound_type') }}
                <select name="inbound_type" required>
                    @foreach ($inboundTypes as $t)<option value="{{ $t }}" @selected(old('inbound_type', 'loose_truck') === $t)>{{ __('warehouse.inbound_types.'.$t) }}</option>@endforeach
                </select>
            </label>
        </div>
        <div class="grid">
            <label>{{ __('warehouse.receiving.unplanned.delivery_reference') }}<input type="text" name="delivery_reference" class="scan" maxlength="100" value="{{ old('delivery_reference') }}" autofocus></label>
            <label>{{ __('warehouse.receiving.location') }}
                <select name="receiving_location_id" id="location-select" required>
                    @foreach ($receivingLocations as $warehouseId => $list)
                        @foreach ($list as $loc)<option value="{{ $loc->id }}" data-warehouse="{{ $warehouseId }}" @selected((int) old('receiving_location_id') === $loc->id)>{{ $loc->full_code }}</option>@endforeach
                    @endforeach
                </select>
            </label>
        </div>

        <fieldset>
            <legend>{{ __('warehouse.receiving.unplanned.rows') }}</legend>
            @error('rows')<p><mark>{{ $message }}</mark></p>@enderror
            <div class="overflow-auto">
                <table class="form-rows" id="receipt-rows">
                    <thead><tr>
                        <th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.receiving.received_cartons') }}</th><th>{{ __('warehouse.receiving.damaged_cartons') }}</th>
                        <th>{{ __('warehouse.receiving.unit_type') }}</th><th>{{ __('warehouse.receiving.unplanned.unit_count') }}</th><th>{{ __('warehouse.receiving.unplanned.weight_total') }}</th><th>{{ __('warehouse.receiving.unplanned.variance_reason') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rows as $index => $row)
                            @include('warehouse::receiving._unplanned-row', ['index' => $index, 'row' => $row, 'unitTypes' => $unitTypes])
                        @endforeach
                    </tbody>
                </table>
            </div>
            <button type="button" class="secondary outline form-rows-add" id="add-receipt-row">{{ __('warehouse.receiving.unplanned.add_row') }}</button>
            <template id="receipt-row-template">@include('warehouse::receiving._unplanned-row', ['index' => '__INDEX__', 'row' => ['unit_type' => 'pallet', 'unit_count' => 1], 'unitTypes' => $unitTypes])</template>
        </fieldset>
        <label>{{ __('warehouse.receipts.notes') }}<textarea name="notes" rows="2">{{ old('notes') }}</textarea></label>
        <button type="submit">{{ __('warehouse.receiving.unplanned.submit') }}</button>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        // Receiving locations follow the chosen warehouse (client-side filter; the server validates the pair again).
        const warehouse = document.getElementById('warehouse-select');
        const locations = document.getElementById('location-select');
        const syncLocations = () => {
            let firstVisible = null;
            Array.from(locations.options).forEach(option => {
                const visible = option.dataset.warehouse === warehouse.value;
                option.hidden = !visible;
                option.disabled = !visible;
                if (visible && firstVisible === null) firstVisible = option;
            });
            if (locations.selectedOptions.length === 0 || locations.selectedOptions[0].disabled) {
                if (firstVisible) firstVisible.selected = true;
            }
        };
        warehouse.addEventListener('change', syncLocations);
        syncLocations();

        // Goods rows: add clones the <template> with the next free index; removing the only row just clears it.
        const body = document.querySelector('#receipt-rows tbody');
        const template = document.getElementById('receipt-row-template');
        const next = () => Math.max(-1, ...Array.from(body.querySelectorAll('tr')).map(tr => Number(tr.dataset.index) || 0)) + 1;
        document.getElementById('add-receipt-row').addEventListener('click', () => {
            body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next())));
            body.lastElementChild.querySelector('input')?.focus();
        });
        body.addEventListener('click', event => {
            const button = event.target.closest('button.remove-row');
            if (!button) return;
            const row = button.closest('tr');
            if (body.querySelectorAll('tr').length > 1) row.remove();
            else row.querySelectorAll('input').forEach(input => { input.value = ''; });
        });
    })();
</script>
@endpush
