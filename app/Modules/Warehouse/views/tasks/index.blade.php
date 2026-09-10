@extends('layouts.app')

@section('title', __('warehouse.tasks.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('warehouse.tasks.title') }}</h1>
        @role('admin|warehouse_supervisor|warehouse_operator')<p style="text-align:right"><a role="button" href="{{ route('warehouse.tasks.create') }}">{{ __('warehouse.tasks.create') }}</a></p>@endrole
    </header>
    <form method="get" class="grid">
        <select name="status"><option value="">{{ __('warehouse.tasks.status') }}: {{ __('platform.jobs.all') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('warehouse.task_statuses.'.$s) }}</option>@endforeach</select>
        <select name="task_type"><option value="">{{ __('warehouse.tasks.type') }}: {{ __('platform.jobs.all') }}</option>@foreach ($types as $t)<option value="{{ $t }}" @selected(($filters['task_type'] ?? '') === $t)>{{ __('warehouse.task_types.'.$t) }}</option>@endforeach</select>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($tasks->isEmpty())
        <p class="text-muted">{{ __('warehouse.tasks.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.tasks.task_no') }}</th><th>{{ __('warehouse.tasks.type') }}</th><th>{{ __('warehouse.tasks.source') }}</th><th>{{ __('warehouse.tasks.container') }}</th><th>{{ __('warehouse.tasks.status') }}</th><th>{{ __('warehouse.tasks.billable_qty') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($tasks as $t)
                @php($isVas = in_array($t->task_type, \App\Support\Enums::VAS_TASK_TYPES, true))
                @php($isSystem = in_array($t->task_type, \App\Support\Enums::SYSTEM_TASK_TYPES, true) || $t->task_type === 'receiving')
                @php($open = ! in_array($t->status, ['done', 'cancelled'], true))
                <tr>
                    <td>{{ $t->task_no }}</td>
                    <td>{{ __('warehouse.task_types.'.$t->task_type) }} @if ($isSystem)<span class="badge" data-tone="muted">{{ __('warehouse.tasks.system_record') }}</span>@endif</td>
                    <td>
                        @if ($t->asn)<a href="{{ route('warehouse.asns.show', $t->asn) }}">{{ $t->asn->asn_no }}</a>@endif
                        @if ($t->order_id)<a href="{{ route('orders.show', $t->order_id) }}">{{ __('warehouse.tasks.order') }} #{{ $t->order_id }}</a>@endif
                        @if ($t->task_type === 'pick' && $t->wave_id)<a href="{{ route('warehouse.outbound.waves.show', $t->wave_id) }}">{{ __('warehouse.tasks.open_wave') }}</a>@endif
                        @if (in_array($t->task_type, ['pack', 'load'], true))<a href="{{ route('warehouse.outbound.index') }}">{{ __('warehouse.tasks.open_outbound') }}</a>@endif
                    </td>
                    <td>{{ $t->container?->container_no }}</td>
                    <td><span class="badge" data-tone="{{ $t->status === 'done' ? 'ok' : ($t->status === 'cancelled' ? 'muted' : 'warn') }}">{{ __('warehouse.task_statuses.'.$t->status) }}</span></td>
                    <td>{{ $t->billable_qty }} {{ $t->billable_uom ? __('warehouse.uoms.'.$t->billable_uom) : '' }} @if ($t->hours_business || $t->hours_after_hours)· {{ $t->hours_business }}h / {{ $t->hours_after_hours }}h @endif</td>
                    <td>
                        @role('admin|warehouse_supervisor|warehouse_operator')
                            @if ($open && $isVas)
                                <form method="post" action="{{ route('warehouse.tasks.complete', $t) }}" class="inline">
                                    @csrf
                                    @if ($t->task_type === 'labour' || $t->task_type === 'vas_other')
                                        <input type="number" step="0.25" min="0" name="hours_business" placeholder="{{ __('warehouse.tasks.hours_business') }}" style="width:7rem">
                                        <input type="number" step="0.25" min="0" name="hours_after_hours" placeholder="{{ __('warehouse.tasks.hours_after_hours') }}" style="width:7rem">
                                    @elseif ($t->task_type === 'scanning')
                                        <input type="number" min="0" name="scan_count" placeholder="{{ __('warehouse.tasks.scan_count') }}" style="width:7rem">
                                    @elseif ($t->task_type !== 'devanning')
                                        <input type="number" step="0.001" min="0" name="billable_qty" placeholder="{{ __('warehouse.tasks.billable_qty') }}" style="width:7rem">
                                        <select name="billable_uom" style="width:8rem">@foreach ($uoms as $u)<option value="{{ $u }}" @selected($u === ($t->task_type === 'waste' ? 'cbm' : 'pallet'))>{{ __('warehouse.uoms.'.$u) }}</option>@endforeach</select>
                                    @endif
                                    <button type="submit">{{ __('warehouse.tasks.complete') }}</button>
                                </form>
                            @endif
                            @if ($open && ! in_array($t->task_type, \App\Support\Enums::SYSTEM_TASK_TYPES, true))
                                <form method="post" action="{{ route('warehouse.tasks.cancel', $t) }}" class="inline" onsubmit="return confirm('{{ __('warehouse.tasks.cancel_confirm') }}')">@csrf<button type="submit" class="secondary outline" style="padding:.15rem .6rem">{{ __('warehouse.tasks.cancel') }}</button></form>
                                @if (! $isVas)<small class="text-muted">{{ __('warehouse.tasks.legacy_hint') }}</small>@endif
                            @endif
                        @endrole
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $tasks->links() }}
    @endif
@endsection
