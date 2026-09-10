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
    @else
        <form method="post" action="{{ route('warehouse.receiving.bulk_store', $asn) }}" id="bulk-receive">
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
            <div class="overflow-auto"><table class="dense form-rows">
                <thead><tr>
                    <th>{{ __('warehouse.receiving.bulk.include') }}</th><th>#</th><th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.asns.container_no') }}</th>
                    <th class="num">{{ __('warehouse.asns.expected') }}</th><th class="num">{{ __('warehouse.receiving.received_cartons') }}</th><th class="num">{{ __('warehouse.receiving.damaged_cartons') }}</th>
                    <th>{{ __('warehouse.receiving.unit_type') }}</th><th class="num">{{ __('warehouse.receiving.bulk.unit_count') }}</th><th class="num">{{ __('warehouse.receiving.bulk.weight_total') }}</th><th>{{ __('warehouse.receiving.bulk.variance_reason') }}</th>
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
    })();
</script>
@endpush
