@extends('layouts.app')

@section('title', __('warehouse.tasks.create'))

@section('content')
    <p><a href="{{ route('warehouse.tasks.index') }}">← {{ __('warehouse.tasks.title') }}</a></p>
    <h1>{{ __('warehouse.tasks.create') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.tasks.hint') }}</small></p>
    @if ($errors->any())
        <article><ul style="margin:0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif
    <form method="post" action="{{ route('warehouse.tasks.store') }}">
        @csrf
        <p class="text-muted"><small>{{ __('warehouse.tasks.bind_hint') }}</small></p>
        <div class="grid">
            <label>{{ __('warehouse.tasks.asn') }}
                <select name="asn_id" id="asn_id">
                    <option value="">—</option>
                    @foreach ($asns as $a)<option value="{{ $a->id }}" data-containers='@json($a->containers->map(fn ($c) => ['id' => $c->id, 'linked' => $c->isLinked(), 'no' => $c->container_no.($c->isLinked() ? ' ('.__('warehouse.asns.physical_container').')' : '')]))' @selected((int) old('asn_id', $selectedAsn) === $a->id)>{{ $a->asn_no }} · {{ $a->client?->name }}</option>@endforeach
                </select>
            </label>
            {{-- Audit 2026-09-22 INBOUND-08: the opening ASN's containers are rendered server side with its only unlinked one preselected; the script rebuilds the list when the ASN changes. --}}
            <label>{{ __('warehouse.tasks.container') }}
                <select name="container_id" id="container_id">
                    <option value="">—</option>
                    @foreach ($initialContainers as $c)<option value="{{ $c->id }}" @selected($selectedContainer === $c->id)>{{ $c->container_no }}@if ($c->isLinked()) ({{ __('warehouse.asns.physical_container') }})@endif</option>@endforeach
                </select>
            </label>
            <label>{{ __('warehouse.tasks.order') }}
                <select name="order_id" id="order_id">
                    <option value="">—</option>
                    @foreach ($orders as $o)<option value="{{ $o->id }}" @selected((int) old('order_id', $selectedOrder) === $o->id)>{{ $o->order_no }} · {{ $o->client?->name }} · {{ __('orders.statuses.operational.'.$o->operational_status) }}</option>@endforeach
                </select>
            </label>
            <label>{{ __('warehouse.tasks.physical_container') }}
                <select name="physical_container_id" id="physical_container_id">
                    <option value="">—</option>
                    @foreach ($physicalContainers as $b)<option value="{{ $b->id }}" @selected((int) old('physical_container_id', $selectedPhysicalContainer) === $b->id)>{{ $b->container_no }} · {{ $b->warehouse?->code }} · {{ __('warehouse.physical_containers.consolidations.'.$b->consolidation) }}</option>@endforeach
                </select>
            </label>
        </div>
        <p class="text-muted"><small>{{ __('warehouse.tasks.box_hint') }}</small></p>
        <div class="grid">
            <label>{{ __('warehouse.tasks.type') }}
                <select name="task_type" required>@foreach ($types as $t)<option value="{{ $t }}" @selected(old('task_type', $selectedType) === $t)>{{ __('warehouse.task_types.'.$t) }}</option>@endforeach</select>
            </label>
            <label>{{ __('warehouse.tasks.notes') }}<input type="text" name="notes" value="{{ old('notes') }}" maxlength="1000"></label>
        </div>
        <p class="text-muted"><small>{{ __('warehouse.tasks.hint_devanning') }}</small></p>
        {{-- Audit 2026-09-22 INBOUND-07 (CR #141): 现在完成 — a finished job is one page: the completion fields switch with the task type and the record is created + completed in one step. --}}
        <fieldset id="complete-now">
            <label><input type="checkbox" name="complete_now" value="1" id="complete-now-toggle" @checked(old('complete_now'))> {{ __('warehouse.tasks.complete_now') }}</label>
            <p class="text-muted"><small>{{ __('warehouse.tasks.complete_now_hint') }}</small></p>
            @error('billable_qty')<p><mark>{{ $message }}</mark></p>@enderror
            <div class="grid" id="completion-fields" hidden>
                <label data-types="wrap waste">{{ __('warehouse.tasks.billable_qty') }}<input type="number" step="0.001" min="0.001" name="billable_qty" value="{{ old('billable_qty') }}"></label>
                <label data-types="wrap waste">{{ __('warehouse.tasks.billable_uom') }}<select name="billable_uom">@foreach ($uoms as $u)<option value="{{ $u }}" @selected(old('billable_uom', 'pallet') === $u)>{{ __('warehouse.uoms.'.$u) }}</option>@endforeach</select></label>
                <label data-types="labour vas_other">{{ __('warehouse.tasks.hours_business') }}<input type="number" step="0.25" min="0" name="hours_business" value="{{ old('hours_business') }}"></label>
                <label data-types="labour vas_other">{{ __('warehouse.tasks.hours_after_hours') }}<input type="number" step="0.25" min="0" name="hours_after_hours" value="{{ old('hours_after_hours') }}"></label>
                <label data-types="scanning">{{ __('warehouse.tasks.scan_count') }}<input type="number" min="1" name="scan_count" value="{{ old('scan_count') }}"></label>
                <label data-types="scanning">{{ __('warehouse.tasks.serials') }}<textarea name="serials" rows="2" class="scan">{{ old('serials') }}</textarea></label>
                <p data-types="devanning" class="text-muted"><small>{{ __('warehouse.tasks.devanning_no_qty') }}</small></p>
            </div>
        </fieldset>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('warehouse.tasks.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection

@push('scripts')
<script>
    const asnSelect = document.getElementById('asn_id'), containerSelect = document.getElementById('container_id');
    const fill = () => {
        const opt = asnSelect.selectedOptions[0];
        const containers = opt && opt.dataset.containers ? JSON.parse(opt.dataset.containers) : [];
        const current = containerSelect.value;
        containerSelect.innerHTML = '<option value="">—</option>' + containers.map(c => `<option value="${c.id}">${c.no}</option>`).join('');
        // Keep the chosen container when it belongs to this ASN; otherwise preselect the ASN's only unlinked container (a linked one is devanned on its box page).
        const unlinked = containers.filter(c => !c.linked);
        containerSelect.value = containers.some(c => String(c.id) === current) ? current : (unlinked.length === 1 ? String(unlinked[0].id) : '');
    };
    asnSelect.addEventListener('change', fill); fill();
    // 现在完成: the completion inputs follow the task type (wrap / waste → quantity + unit; labour / other → hours; scanning → count or serials; devanning → none).
    const typeSelect = document.querySelector('select[name="task_type"]'), toggle = document.getElementById('complete-now-toggle'), fields = document.getElementById('completion-fields');
    const uomSelect = fields.querySelector('select[name="billable_uom"]');
    const switchFields = () => {
        fields.hidden = !toggle.checked;
        fields.querySelectorAll('[data-types]').forEach(el => {
            const on = el.dataset.types.split(' ').includes(typeSelect.value);
            el.hidden = !on;
            el.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = !on || !toggle.checked; });
        });
        if (typeSelect.value === 'waste' && uomSelect.dataset.touched !== '1') uomSelect.value = 'cbm';
        if (typeSelect.value === 'wrap' && uomSelect.dataset.touched !== '1') uomSelect.value = 'pallet';
    };
    uomSelect.addEventListener('change', () => { uomSelect.dataset.touched = '1'; });
    typeSelect.addEventListener('change', switchFields); toggle.addEventListener('change', switchFields); switchFields();
</script>
@endpush
