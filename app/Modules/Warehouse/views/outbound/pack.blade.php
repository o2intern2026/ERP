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

    {{-- Audit 2026-09-22 OUTBOUND-01: three rows + 添加包裹 (a <template> row cloned by the script below) instead of a fixed six; every row has 件数. --}}
    <form method="post" action="{{ route('warehouse.outbound.pack', $task->fulfilment_id) }}" id="pack-form">
        @csrf
        <p class="text-muted"><small>{{ __('warehouse.outbound.packages_hint') }}</small></p>
        @error('packages')<p><mark>{{ $message }}</mark></p>@enderror
        @php($rows = max(3, count((array) old('packages', []))))
        <div class="overflow-auto"><table class="dense" id="packages">
            <thead><tr><th>#</th><th>{{ __('warehouse.outbound.package_type') }}</th><th>{{ __('warehouse.outbound.qty') }}</th><th>{{ __('warehouse.outbound.weight') }}</th><th>{{ __('warehouse.outbound.length') }}</th><th>{{ __('warehouse.outbound.width') }}</th><th>{{ __('warehouse.outbound.height') }}</th></tr></thead>
            <tbody>
            @for ($i = 0; $i < $rows; $i++)
                @include('warehouse::outbound._package-row', ['i' => $i, 'n' => $i + 1, 'default' => $i === 0 ? 'carton' : ''])
            @endfor
            </tbody>
        </table></div>
        <template id="package-row-template">@include('warehouse::outbound._package-row', ['i' => '__INDEX__', 'n' => '', 'default' => ''])</template>
        <button type="button" class="secondary outline" id="add-package">{{ __('warehouse.outbound.add_package') }}</button>
        <button type="submit">{{ __('warehouse.outbound.pack') }}</button>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        // 添加包裹: clone the <template> row with the next free packages[] index; the server validates every row the same way (weight ≥ 0.001, dims ≥ 1, qty 1–200).
        const body = document.querySelector('#packages tbody');
        const template = document.getElementById('package-row-template');
        const next = () => Math.max(-1, ...Array.from(body.querySelectorAll('tr')).map(tr => Number(tr.dataset.index) || 0)) + 1;
        document.getElementById('add-package').addEventListener('click', () => {
            body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(next())));
            const row = body.lastElementChild;
            row.querySelector('.row-no').textContent = String(body.querySelectorAll('tr').length);
            row.querySelector('select')?.focus();
        });
    })();
</script>
@endpush
