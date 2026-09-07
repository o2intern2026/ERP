@extends('layouts.app')

@section('title', __('warehouse.tasks.create'))

@section('content')
    <h1>{{ __('warehouse.tasks.create') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.tasks.hint_devanning') }}</small></p>
    <form method="post" action="{{ route('warehouse.tasks.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('warehouse.tasks.asn') }}
                <select name="asn_id" id="asn_id" required>
                    <option value="">—</option>
                    @foreach ($asns as $a)<option value="{{ $a->id }}" data-containers='@json($a->containers->map(fn ($c) => ['id' => $c->id, 'no' => $c->container_no]))' @selected(old('asn_id', $selectedAsn) == $a->id)>{{ $a->asn_no }} · {{ $a->client->name }}</option>@endforeach
                </select>
            </label>
            <label>{{ __('warehouse.tasks.container') }}<select name="container_id" id="container_id"><option value="">—</option></select></label>
            <label>{{ __('warehouse.tasks.type') }}<select name="task_type" required>@foreach ($types as $t)<option value="{{ $t }}" @selected(old('task_type', 'devanning') === $t)>{{ __('warehouse.task_types.'.$t) }}</option>@endforeach</select></label>
        </div>
        <label>{{ __('warehouse.tasks.notes') }}<input type="text" name="notes" value="{{ old('notes') }}"></label>
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
        containerSelect.innerHTML = '<option value="">—</option>' + containers.map(c => `<option value="${c.id}">${c.no}</option>`).join('');
    };
    asnSelect.addEventListener('change', fill); fill();
</script>
@endpush
