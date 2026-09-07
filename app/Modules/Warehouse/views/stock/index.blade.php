@extends('layouts.app')

@section('title', __('warehouse.stock.title'))

@section('content')
    <h1>{{ __('warehouse.title') }} · {{ __('warehouse.stock.title') }}</h1>
    <form method="get" class="grid">
        <select name="client_id" aria-label="{{ __('warehouse.stock.client') }}">
            <option value="">{{ __('warehouse.stock.client') }}: {{ __('platform.jobs.all') }}</option>
            @foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach
        </select>
        <select name="warehouse_id" aria-label="{{ __('warehouse.asns.warehouse') }}">
            <option value="">{{ __('warehouse.asns.warehouse') }}: {{ __('platform.jobs.all') }}</option>
            @foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) ($filters['warehouse_id'] ?? 0) === $w->id)>{{ $w->code }}</option>@endforeach
        </select>
        <input type="text" name="job_no" placeholder="{{ __('warehouse.stock.job') }}" value="{{ $filters['job_no'] ?? '' }}">
        <input type="text" name="consignment_mark" placeholder="{{ __('warehouse.stock.mark') }}" value="{{ $filters['consignment_mark'] ?? '' }}">
        <input type="text" name="location" class="scan" placeholder="{{ __('warehouse.stock.location') }}" value="{{ $filters['location'] ?? '' }}">
        <select name="condition" aria-label="{{ __('warehouse.stock.condition') }}">
            <option value="">{{ __('warehouse.stock.condition') }}: {{ __('platform.jobs.all') }}</option>
            @foreach ($conditions as $c)<option value="{{ $c }}" @selected(($filters['condition'] ?? '') === $c)>{{ __('warehouse.conditions.'.$c) }}</option>@endforeach
        </select>
        <label><input type="checkbox" name="available_only" value="1" @checked(! empty($filters['available_only']))> {{ __('warehouse.stock.available_only') }}</label>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($units->isEmpty())
        <p class="text-muted">{{ __('warehouse.stock.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr>
                <th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.client') }}</th><th>{{ __('warehouse.stock.job') }}</th>
                <th>{{ __('warehouse.stock.mark') }}</th><th>{{ __('warehouse.stock.description') }}</th><th>{{ __('warehouse.stock.location') }}</th>
                <th>{{ __('warehouse.stock.unit_type') }}</th><th class="num">{{ __('warehouse.stock.on_hand') }}</th><th class="num">{{ __('warehouse.stock.reserved') }}</th>
                <th class="num">{{ __('warehouse.stock.available') }}</th><th>{{ __('warehouse.stock.condition') }}</th><th>{{ __('warehouse.stock.putaway') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($units as $u)
                <tr>
                    <td><a href="{{ route('warehouse.stock.show', $u) }}"><code>{{ $u->label_code }}</code></a></td>
                    <td>{{ $u->asnLine->asn->client->name }}</td>
                    <td>{{ $u->asnLine->asn->job->job_no }}</td>
                    <td>{{ $u->asnLine->consignment_mark }}</td>
                    <td>{{ $u->asnLine->description }}</td>
                    <td>{{ $u->location?->full_code ?? '—' }}</td>
                    <td>{{ __('warehouse.unit_types.'.$u->unit_type) }}</td>
                    <td class="num">{{ $u->qty_on_hand }}</td>
                    <td class="num">{{ $u->qty_reserved }}</td>
                    <td class="num">{{ $u->isAllocatable() ? $u->availableQty() : 0 }}</td>
                    <td><span class="badge" data-tone="{{ $u->condition === 'good' ? 'ok' : 'danger' }}">{{ __('warehouse.conditions.'.$u->condition) }}</span></td>
                    <td>{{ $u->putaway_completed ? __('platform.common.yes') : __('warehouse.stock.not_putaway') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $units->links() }}
    @endif
@endsection
