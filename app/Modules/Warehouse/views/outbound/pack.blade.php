@extends('layouts.app')

@section('title', __('warehouse.outbound.pack'))

@section('content')
    <p><a href="{{ route('warehouse.outbound.index') }}">← {{ __('platform.common.back') }}</a></p>
    <h1>{{ __('warehouse.outbound.pack') }} · {{ $order?->order_no }} <small class="text-muted">{{ __('warehouse.outbound.fulfilment') }} #{{ $task->fulfilment_id }}</small></h1>
    @if ($order)<p class="text-muted">{{ $order->deliver_to_name }} · {{ $order->deliver_to_address }}, {{ $order->deliver_to_suburb }} {{ $order->deliver_to_state }} {{ $order->deliver_to_postcode }} · {{ $order->requested_date }}</p>@endif

    <h2>{{ __('warehouse.outbound.packed_lines') }}</h2>
    <table class="dense">
        <thead><tr><th>{{ __('warehouse.outbound.unit') }}</th><th>{{ __('warehouse.stock.description') }}</th><th class="num">{{ __('warehouse.outbound.picked') }}</th></tr></thead>
        <tbody>@foreach ($task->lines->where('completed_qty', '>', 0) as $l)<tr><td><code>{{ $l->stockUnit->label_code }}</code> {{ __('warehouse.unit_types.'.$l->stockUnit->unit_type) }}</td><td>{{ $l->stockUnit->asnLine?->description }}</td><td class="num">{{ $l->completed_qty }}</td></tr>@endforeach</tbody>
    </table>

    <form method="post" action="{{ route('warehouse.outbound.pack', $task->fulfilment_id) }}">
        @csrf
        <p class="text-muted"><small>{{ __('warehouse.outbound.packages_hint') }}</small></p>
        @error('packages')<p><mark>{{ $message }}</mark></p>@enderror
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>#</th><th>{{ __('warehouse.outbound.package_type') }}</th><th>{{ __('warehouse.outbound.weight') }}</th><th>{{ __('warehouse.outbound.length') }}</th><th>{{ __('warehouse.outbound.width') }}</th><th>{{ __('warehouse.outbound.height') }}</th></tr></thead>
            <tbody>
            @for ($i = 0; $i < 6; $i++)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><select name="packages[{{ $i }}][package_type]"><option value="">—</option>@foreach ($packageTypes as $t)<option value="{{ $t }}" @selected(old("packages.$i.package_type", $i === 0 ? 'carton' : '') === $t)>{{ __('warehouse.package_types.'.$t) }}</option>@endforeach</select></td>
                    <td><input type="number" step="0.001" min="0.001" name="packages[{{ $i }}][weight_kg]" value="{{ old("packages.$i.weight_kg") }}"></td>
                    <td><input type="number" min="1" name="packages[{{ $i }}][length_mm]" value="{{ old("packages.$i.length_mm") }}"></td>
                    <td><input type="number" min="1" name="packages[{{ $i }}][width_mm]" value="{{ old("packages.$i.width_mm") }}"></td>
                    <td><input type="number" min="1" name="packages[{{ $i }}][height_mm]" value="{{ old("packages.$i.height_mm") }}"></td>
                </tr>
            @endfor
            </tbody>
        </table></div>
        <button type="submit">{{ __('warehouse.outbound.pack') }}</button>
    </form>
@endsection
