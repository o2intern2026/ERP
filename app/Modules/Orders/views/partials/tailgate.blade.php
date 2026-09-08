{{-- Item 6 (tester feedback): 尾板车 checkbox on the order forms. Vanilla JS ticks it when the heaviest single piece (line weight ÷
     cartons, declared package weight) reaches the client's threshold or the address is residential — but never re-ticks a box a
     person changed by hand (touched flag → tailgate_manual = 1 → the server stores tailgate_reason = manual and TailgateRule
     leaves the order alone). Staff form: the threshold follows the selected client (data-tailgate-kg on the client option). --}}
@php
    $threshold = (float) ($tailgateThresholdKg ?? \App\Modules\Orders\Services\TailgateRule::DEFAULT_WEIGHT_KG);
@endphp
<fieldset id="tailgate-field">
    <label>
        <input type="hidden" name="tailgate_required" value="0">
        <input type="checkbox" id="tailgate-required" name="tailgate_required" value="1" @checked((bool) old('tailgate_required', false))>
        {{ __('orders.tailgate.form_label') }}
    </label>
    <input type="hidden" id="tailgate-manual" name="tailgate_manual" value="{{ old('tailgate_manual') ? 1 : 0 }}">
    <small id="tailgate-hint" class="text-muted" data-template="{{ __('orders.tailgate.form_hint', ['kg' => ':kg']) }}">{{ __('orders.tailgate.form_hint', ['kg' => $threshold + 0]) }}</small>
</fieldset>
<script>
    (() => {
        const box = document.getElementById('tailgate-required');
        const manual = document.getElementById('tailgate-manual');
        const hint = document.getElementById('tailgate-hint');
        const form = box.form;
        const clientSelect = form.querySelector('[name="client_id"]');
        const fallbackKg = {{ json_encode($threshold) }};
        let touched = manual.value === '1';

        const thresholdKg = () => {
            const kg = parseFloat(clientSelect?.selectedOptions[0]?.dataset.tailgateKg ?? '');
            return Number.isFinite(kg) && kg > 0 ? kg : fallbackKg;
        };
        const number = (row, suffix) => parseFloat(row.querySelector('[name$="' + suffix + '"]')?.value) || 0;
        const heaviestPieceKg = () => {
            let max = 0;
            form.querySelectorAll('tr.goods-line').forEach(row => {
                if (row.closest('[hidden]')) return;
                const weight = number(row, '[actual_weight_kg]');
                if (weight > 0) max = Math.max(max, weight / Math.max(1, Math.floor(number(row, '[carton_qty]')) || 1)); // a line's weight is the line total
            });
            form.querySelectorAll('tr.declared-package').forEach(row => {
                if (row.closest('[hidden]')) return;
                max = Math.max(max, number(row, '[weight_kg]')); // declared packages are single pieces
            });
            return max;
        };
        const evaluate = () => {
            const kg = thresholdKg();
            hint.textContent = hint.dataset.template.replace(':kg', String(kg));
            if (touched) return;
            const residential = form.querySelector('[name="deliver_to_address_type"]')?.value === 'residential';
            const heaviest = heaviestPieceKg();
            box.checked = (heaviest > 0 && heaviest >= kg) || residential;
        };

        box.addEventListener('change', () => { touched = true; manual.value = '1'; });
        form.addEventListener('input', evaluate);
        form.addEventListener('change', evaluate);
        evaluate();
    })();
</script>
