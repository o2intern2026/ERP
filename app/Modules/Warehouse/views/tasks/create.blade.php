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
</script>
@endpush
