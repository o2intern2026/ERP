@extends('layouts.app')

@section('title', __('warehouse.physical_containers.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('warehouse.physical_containers.title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('warehouse.physical_containers.create') }}">{{ __('warehouse.physical_containers.create') }}</a></p>
    </header>
    <p class="text-muted"><small>{{ __('warehouse.physical_containers.hint') }}</small></p>
    <form method="get" class="grid">
        <input type="text" name="container_no" class="scan" placeholder="{{ __('warehouse.physical_containers.container_no') }}" value="{{ $filters['container_no'] ?? '' }}" maxlength="20">
        <select name="status"><option value="">{{ __('warehouse.physical_containers.status') }}: {{ __('platform.jobs.all') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('warehouse.physical_containers.statuses.'.$s) }}</option>@endforeach</select>
        <select name="consolidation"><option value="">{{ __('warehouse.physical_containers.consolidation') }}: {{ __('platform.jobs.all') }}</option>@foreach ($consolidations as $c)<option value="{{ $c }}" @selected(($filters['consolidation'] ?? '') === $c)>{{ __('warehouse.physical_containers.consolidations.'.$c) }}</option>@endforeach</select>
        <label style="align-self:center"><input type="checkbox" name="sideloader" value="1" @checked($filters['sideloader'] ?? false)> {{ __('warehouse.physical_containers.filter_sideloader') }}</label>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($boxes->isEmpty())
        <p class="text-muted">{{ __('warehouse.physical_containers.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>{{ __('warehouse.physical_containers.container_no') }}</th><th>{{ __('warehouse.physical_containers.warehouse') }}</th><th>{{ __('warehouse.physical_containers.size') }}</th><th>{{ __('warehouse.physical_containers.unpack_mode') }}</th>
                <th>{{ __('warehouse.physical_containers.consolidation') }}</th><th class="num">{{ __('warehouse.physical_containers.members_count') }}</th><th>{{ __('warehouse.physical_containers.status') }}</th>
                <th>{{ __('warehouse.physical_containers.eta_date') }}</th><th>{{ __('warehouse.physical_containers.arrived_at') }}</th><th>{{ __('warehouse.physical_containers.sideloader_short') }}</th><th>{{ __('warehouse.physical_containers.devanning_task') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($boxes as $b)
                <tr>
                    <td><a href="{{ route('warehouse.physical_containers.show', $b) }}">{{ $b->container_no }}</a> @if ($b->allocation_stale)<span class="badge" data-tone="danger">{{ __('warehouse.physical_containers.stale_badge') }}</span>@endif</td>
                    <td>{{ $b->warehouse->code }}</td><td>{{ __('warehouse.container_sizes.'.$b->size) }}</td><td>{{ __('warehouse.unpack_modes.'.$b->unpack_mode) }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.physical_containers.consolidations.', $b->consolidation) !!}</td>
                    <td class="num">{{ $b->members_count }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.physical_containers.statuses.', $b->status) !!}</td>
                    <td>{{ $b->eta_date?->format('Y-m-d') ?? '—' }}</td><td>{{ $b->arrived_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>{{ $b->sideloader_required ? __('platform.common.yes') : '—' }}</td>
                    <td>@if ($b->devanningTask){{ $b->devanningTask->task_no }} {!! \App\Support\Ui\StatusBadge::render('warehouse.task_statuses.', $b->devanningTask->status) !!}@else<span class="text-muted">—</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $boxes->links() }}
    @endif
@endsection
