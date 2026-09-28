@extends('layouts.app')

@section('title', __('warehouse.pallets.one', ['no' => $pallet->pallet_no]))

@php($free = $pallet->status === 'empty' && $pallet->location_id === null)
@section('content')
    <p><a href="{{ route('warehouse.pallets.index') }}">← {{ __('warehouse.pallets.title') }}</a></p>
    <h1><code>{{ $pallet->pallet_no }}</code> {!! \App\Support\Ui\StatusBadge::render('warehouse.pallet_statuses.', $pallet->status) !!}@if ($free) <span class="badge" data-tone="ok">{{ __('warehouse.pallets.free') }}</span>@endif
        <small><a href="{{ route('warehouse.labels.pallets', ['ids' => [$pallet->id]]) }}" target="_blank">{{ __('warehouse.labels.pallets') }}</a></small></h1>
    <article class="kv-card">
        <p style="margin:0">
            <strong>{{ __('warehouse.locations.warehouse') }}</strong> {{ $pallet->warehouse?->code }} ·
            <strong>{{ __('warehouse.stock.location') }}</strong> <code>{{ $pallet->location?->full_code ?? '—' }}</code> ·
            <strong>{{ __('warehouse.stock.client') }}</strong> {{ $free ? '—' : ($pallet->client?->name ?? '—') }}
            @if (! $free && $pallet->job_id) · Job #{{ $pallet->job_id }}@endif
            <br><small class="text-muted">{{ __('warehouse.pallets.received_at') }} {{ $pallet->received_at?->format('Y-m-d H:i') ?? '—' }}@if ($pallet->released_at) · {{ __('warehouse.pallets.released_at') }} {{ $pallet->released_at->format('Y-m-d H:i') }}@endif @if ($pallet->reuse_count > 0) · {{ __('warehouse.pallets.reused', ['count' => $pallet->reuse_count]) }}@endif</small>
        </p>
    </article>
    @error('release')<p><mark>{{ $message }}</mark></p>@enderror
    @error('repalletise')<p><mark>{{ $message }}</mark></p>@enderror

    <h2>{{ __('warehouse.pallets.units_title') }} <small class="text-muted">{{ __('warehouse.pallets.units_summary', ['units' => $units->where('qty_on_hand', '>', 0)->count(), 'cartons' => (int) $units->sum('qty_on_hand')]) }}</small></h2>
    @if ($units->isEmpty())
        <p class="text-muted">{{ __('warehouse.pallets.no_units') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.stock.label_code') }}</th><th>{{ __('warehouse.stock.mark') }}</th><th class="num">{{ __('warehouse.pallets.cartons') }}</th><th>{{ __('warehouse.stock.condition') }}</th>@role('admin|warehouse_supervisor|warehouse_operator')<th>{{ __('warehouse.pallets.repalletise') }}</th>@endrole</tr></thead>
            <tbody>
            @foreach ($units as $u)
                <tr>
                    <td><a href="{{ route('warehouse.stock.show', $u) }}"><code>{{ $u->label_code }}</code></a></td>
                    <td>{{ $u->asnLine?->consignment_mark }} {{ $u->asnLine?->description }}</td>
                    <td class="num">{{ $u->qty_on_hand }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.conditions.', $u->condition) !!}</td>
                    @role('admin|warehouse_supervisor|warehouse_operator')
                    <td>
                        @if ($u->qty_on_hand > 0)
                            <form method="post" action="{{ route('warehouse.pallets.repalletise', $pallet) }}" class="inline" style="display:flex;gap:.3rem;margin:0">
                                @csrf
                                <input type="hidden" name="unit_code" value="{{ $u->label_code }}">
                                <input type="text" name="target" class="scan" maxlength="30" placeholder="{{ __('warehouse.pallets.target_placeholder') }}" style="width:11rem;margin:0" aria-label="{{ __('warehouse.pallets.target') }}">
                                <button type="submit" class="secondary outline" style="width:auto;margin:0;padding:.2rem .6rem">{{ __('warehouse.pallets.repalletise_submit') }}</button>
                            </form>
                        @endif
                    </td>
                    @endrole
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @role('admin|warehouse_supervisor|warehouse_operator')<p class="text-muted"><small>{{ __('warehouse.pallets.repalletise_hint') }}</small></p>@endrole
    @endif

    @role('admin|warehouse_supervisor|warehouse_operator')
        @if (! $free && $units->where('qty_on_hand', '>', 0)->isEmpty())
            <form method="post" action="{{ route('warehouse.pallets.release', $pallet) }}" id="pallet-release">
                @csrf
                <button type="submit" class="secondary">{{ __('warehouse.pallets.release_submit') }}</button>
                <small class="text-muted">{{ __('warehouse.pallets.release_hint') }}</small>
            </form>
        @endif
    @endrole

    @role('admin|warehouse_supervisor')
        <h2>{{ __('warehouse.pallets.edit_title') }}</h2>
        <form method="post" action="{{ route('warehouse.pallets.update', $pallet) }}" id="pallet-edit" class="grid">
            @csrf
            <select name="pallet_source" aria-label="{{ __('warehouse.receiving.pallet_source') }}">@foreach ($palletSources as $s)<option value="{{ $s }}" @selected(old('pallet_source', $pallet->pallet_source ?? 'warehouse_plain') === $s)>{{ __('warehouse.pallet_sources.'.$s) }}</option>@endforeach</select>
            <select name="pallet_class" aria-label="{{ __('warehouse.receiving.pallet_class') }}"><option value="">{{ __('warehouse.receiving.pallet_class') }}: —</option>@foreach ($palletClasses as $c)<option value="{{ $c }}" @selected(old('pallet_class', $pallet->pallet_class) === $c)>{{ __('warehouse.pallet_classes.'.$c) }}</option>@endforeach</select>
            <input type="number" name="length_mm" min="1" placeholder="{{ __('warehouse.receiving.length') }}" value="{{ old('length_mm', $pallet->length_mm) }}">
            <input type="number" name="width_mm" min="1" placeholder="{{ __('warehouse.receiving.width') }}" value="{{ old('width_mm', $pallet->width_mm) }}">
            <input type="number" name="height_mm" min="1" placeholder="{{ __('warehouse.receiving.height') }}" value="{{ old('height_mm', $pallet->height_mm) }}">
            <input type="number" name="weight_kg" min="0" step="0.001" placeholder="{{ __('warehouse.receiving.weight') }}" value="{{ old('weight_kg', $pallet->weight_kg) }}">
            <input type="text" name="pallet_class_overridden_reason" maxlength="255" placeholder="{{ __('warehouse.pallets.class_reason') }}" value="{{ old('pallet_class_overridden_reason', $pallet->pallet_class_overridden_reason) }}">
            <button type="submit" class="secondary">{{ __('warehouse.pallets.edit_submit') }}</button>
        </form>
    @endrole
@endsection
