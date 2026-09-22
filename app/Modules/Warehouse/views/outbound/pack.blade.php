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
        {{-- Audit 2026-09-22 OUTBOUND-09 (CR #141): row-numbered refusals (type / dims missing on a weighed row). --}}
        @foreach ($errors->keys() as $key)@if (str_starts_with($key, 'packages.'))<p><mark>{{ $errors->first($key) }}</mark></p>@endif @endforeach
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
        {{-- OUTBOUND-09: what is about to be posted — pieces, total weight, pallets — updated live; Enter in a box moves on instead of submitting. --}}
        <p id="pack-summary"><strong>{{ __('warehouse.outbound.summary_label') }}</strong> <span id="pack-summary-text">—</span></p>
        <button type="submit" id="pack-submit">{{ __('warehouse.outbound.pack') }}</button>
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
            summarise();
        });
        // OUTBOUND-09: a weighed row copies the type of the row above when its own is empty; the summary counts pieces / weight / pallets live.
        const rows = () => Array.from(body.querySelectorAll('tr'));
        const summaryText = document.getElementById('pack-summary-text');
        const template_ = @json(__('warehouse.outbound.summary'));
        const summarise = () => {
            let pieces = 0, weight = 0, pallets = 0;
            rows().forEach(tr => {
                const w = Number(tr.querySelector('input[name$="[weight_kg]"]').value) || 0;
                if (w <= 0) return;
                const qty = Math.max(1, Number(tr.querySelector('input[name$="[qty]"]').value) || 1);
                pieces += qty; weight += w * qty;
                if (tr.querySelector('select').value === 'pallet') pallets += qty;
            });
            summaryText.textContent = template_.replace(':pieces', String(pieces)).replace(':weight', weight.toFixed(weight % 1 ? 1 : 0)).replace(':pallets', String(pallets));
        };
        body.addEventListener('input', event => {
            if (event.target.name && event.target.name.endsWith('[weight_kg]') && event.target.value) {
                const tr = event.target.closest('tr'), select = tr.querySelector('select'), prev = tr.previousElementSibling;
                if (!select.value && prev) select.value = prev.querySelector('select').value;
            }
            summarise();
        });
        body.addEventListener('change', summarise);
        body.addEventListener('keydown', event => {
            if (event.key !== 'Enter' || event.target.tagName !== 'INPUT') return;
            event.preventDefault(); // Enter in the first weight box used to submit a half-filled form
            const inputs = Array.from(body.querySelectorAll('input, select')), i = inputs.indexOf(event.target);
            (inputs[i + 1] || document.getElementById('pack-submit')).focus();
        });
        summarise();
    })();
</script>
@endpush
