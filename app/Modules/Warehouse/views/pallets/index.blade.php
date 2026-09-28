@extends('layouts.app')

@section('title', __('warehouse.pallets.title'))

@section('content')
    <h1>{{ __('warehouse.pallets.title') }} <small class="text-muted">{{ __('warehouse.pallets.free_count', ['count' => $freeCount]) }}</small></h1>
    <p class="text-muted"><small>{{ __('warehouse.pallets.hint') }}</small></p>
    <form method="get" class="grid">
        <select name="warehouse_id" aria-label="{{ __('warehouse.locations.warehouse') }}"><option value="">{{ __('warehouse.locations.warehouse') }}: {{ __('platform.jobs.all') }}</option>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) ($filters['warehouse_id'] ?? 0) === $w->id)>{{ $w->code }}</option>@endforeach</select>
        <select name="client_id" aria-label="{{ __('warehouse.stock.client') }}"><option value="">{{ __('warehouse.stock.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <select name="status" aria-label="{{ __('warehouse.pallets.status') }}"><option value="">{{ __('warehouse.pallets.status') }}: {{ __('platform.jobs.all') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('warehouse.pallet_statuses.'.$s) }}</option>@endforeach</select>
        <input type="text" name="q" class="scan" placeholder="{{ __('warehouse.pallets.search') }}" value="{{ $filters['q'] ?? '' }}">
        <label><input type="checkbox" name="free" value="1" @checked(! empty($filters['free']))> {{ __('warehouse.pallets.free_only') }}</label>
        <button type="submit" class="secondary">{{ __('warehouse.map.show') }}</button>
    </form>
    @if ($pallets->isEmpty())
        <p>{{ __('warehouse.pallets.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.pallets.number') }}</th><th>{{ __('warehouse.pallets.status') }}</th><th>{{ __('warehouse.stock.location') }}</th><th>{{ __('warehouse.stock.client') }}</th><th class="num">{{ __('warehouse.pallets.lines') }}</th><th class="num">{{ __('warehouse.pallets.cartons') }}</th><th>{{ __('warehouse.receiving.pallet_source') }}</th><th>{{ __('warehouse.receiving.pallet_class') }}</th><th>{{ __('warehouse.pallets.dims') }}</th><th>{{ __('warehouse.pallets.received_at') }}</th></tr></thead>
            <tbody>
            @foreach ($pallets as $p)
                @php($free = $p->status === 'empty' && $p->location_id === null)
                <tr>
                    <td><a href="{{ route('warehouse.pallets.show', $p) }}"><code>{{ $p->pallet_no }}</code></a></td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.pallet_statuses.', $p->status) !!}@if ($free) <span class="badge" data-tone="ok">{{ __('warehouse.pallets.free') }}</span>@endif</td>
                    <td><code>{{ $p->location?->full_code ?? '—' }}</code></td>
                    <td>{{ $free ? '—' : $p->client?->name }}</td>
                    <td class="num">{{ $p->units_count }}</td>
                    <td class="num">{{ (int) $p->units_sum_qty_on_hand }}</td>
                    <td>{{ $p->pallet_source ? __('warehouse.pallet_sources.'.$p->pallet_source) : '—' }}</td>
                    <td>{{ $p->pallet_class ? __('warehouse.pallet_classes.'.$p->pallet_class) : '—' }}</td>
                    <td>{{ $p->length_mm ? $p->length_mm.'×'.$p->width_mm.'×'.$p->height_mm : '—' }}@if ($p->weight_kg !== null) · {{ rtrim(rtrim((string) $p->weight_kg, '0'), '.') }} kg @endif</td>
                    <td>{{ $p->received_at?->format('Y-m-d') ?? '—' }}@if ($p->reuse_count > 0) <small class="text-muted">{{ __('warehouse.pallets.reused', ['count' => $p->reuse_count]) }}</small>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $pallets->links() }}
    @endif
@endsection
