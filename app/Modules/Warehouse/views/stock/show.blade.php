@extends('layouts.app')

@section('title', $unit->label_code)

@section('content')
    <p><a href="{{ route('warehouse.index') }}">← {{ __('platform.common.back') }}</a></p>
    <h1><code>{{ $unit->label_code }}</code> <small class="text-muted">{{ __('warehouse.unit_types.'.$unit->unit_type) }}</small></h1>
    <div class="grid">
        <article>
            <p>{{ __('warehouse.stock.client') }}: {{ $unit->asnLine->asn->client->name }}<br>
               {{ __('warehouse.stock.job') }}: {{ $unit->asnLine->asn->job->job_no }} · <a href="{{ route('warehouse.asns.show', $unit->asnLine->asn) }}">{{ $unit->asnLine->asn->asn_no }}</a><br>
               {{ __('warehouse.stock.mark') }}: {{ $unit->asnLine->consignment_mark }} · {{ $unit->asnLine->description }}</p>
        </article>
        <article>
            <p>{{ __('warehouse.stock.location') }}: <strong>{{ $unit->location?->full_code ?? '—' }}</strong> ({{ $unit->warehouse->code }})<br>
               {{ __('warehouse.stock.on_hand') }} {{ $unit->qty_on_hand }} · {{ __('warehouse.stock.reserved') }} {{ $unit->qty_reserved }} @if ($unit->qty_frozen > 0)· <span class="badge" data-tone="warn" title="{{ __('warehouse.stock.frozen_hint', ['qty' => $unit->qty_frozen]) }}">{{ __('warehouse.stock.frozen') }} {{ $unit->qty_frozen }}</span> @endif· {{ __('warehouse.stock.available') }} {{ $unit->isAllocatable() ? $unit->availableQty() : 0 }}<br>
               {{-- CR #142: 找不到 cartons stay on hand (storage billed) but out of available until the 差异盘点 line is counted. --}}
               @if ($unit->qty_frozen > 0)<small class="text-muted">{{ __('warehouse.stock.frozen_hint', ['qty' => $unit->qty_frozen]) }}</small><br>@endif
               {{ __('warehouse.stock.condition') }}: {{ __('warehouse.conditions.'.$unit->condition) }} · {{ __('warehouse.stock.putaway') }}: {{ $unit->putaway_completed ? __('platform.common.yes') : __('platform.common.no') }}</p>
        </article>
        @if ($unit->unit_type === 'pallet')
            <article>
                <p>{{ __('warehouse.stock.dims') }}: {{ $unit->length_mm }} × {{ $unit->width_mm }} × {{ $unit->height_mm }} · {{ __('warehouse.stock.weight') }}: {{ $unit->weight_kg }}<br>
                   {{ __('warehouse.stock.pallet_class') }}: {{ $unit->pallet_class ? __('warehouse.pallet_classes.'.$unit->pallet_class) : __('warehouse.stock.pallet_class_unclassified') }} · {{ __('warehouse.stock.pallet_source') }}: {{ $unit->pallet_source ? __('warehouse.pallet_sources.'.$unit->pallet_source) : '—' }}</p>
            </article>
        @endif
    </div>
    @if ($unit->condition !== 'good')
        <p><strong>{{ __('warehouse.moves.condition_reason') }}:</strong> {{ $unit->condition_reason }} <small class="text-muted">{{ $unit->condition_changed_at?->format('Y-m-d H:i') }}</small></p>
    @endif
    <p><a href="{{ route('warehouse.labels.units', ['ids' => [$unit->id]]) }}" target="_blank">{{ __('warehouse.labels.units') }}</a></p>

    @role('admin|warehouse_supervisor|warehouse_operator')
        <div class="grid">
            <article>
                <header>{{ __('warehouse.moves.title') }}</header>
                {{-- Audit 2026-09-10: scan-gun forms keep the typed value after a refusal so one character can be corrected instead of re-scanning; `_form` says which card failed. --}}
                <form method="post" action="{{ route('warehouse.stock.move', $unit) }}">
                    @csrf
                    <input type="hidden" name="_form" value="move">
                    <input type="text" name="location_code" class="scan" placeholder="{{ __('warehouse.moves.to') }}" value="{{ old('_form') === 'move' ? old('location_code') : '' }}" required>
                    <input type="text" name="reason" placeholder="{{ __('warehouse.moves.reason') }}" value="{{ old('_form') === 'move' ? old('reason') : '' }}">
                    <button type="submit" class="secondary">{{ __('warehouse.moves.do') }}</button>
                </form>
            </article>
            @if ($unit->condition === 'good')
                <article>
                    <header>{{ __('warehouse.moves.quarantine_title') }}</header>
                    <form method="post" action="{{ route('warehouse.stock.quarantine', $unit) }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="_form" value="quarantine">
                        <select name="condition"><option value="damaged" @selected(old('_form') === 'quarantine' && old('condition') === 'damaged')>{{ __('warehouse.conditions.damaged') }}</option><option value="quarantine" @selected(old('_form') === 'quarantine' && old('condition') === 'quarantine')>{{ __('warehouse.conditions.quarantine') }}</option></select>
                        <input type="text" name="reason" placeholder="{{ __('warehouse.moves.quarantine_reason') }}" value="{{ old('_form') === 'quarantine' ? old('reason') : '' }}" required>
                        <label>{{ __('warehouse.moves.photos') }}<input type="file" name="photos[]" accept="image/*" multiple capture="environment"></label>
                        <button type="submit" class="secondary">{{ __('warehouse.moves.quarantine_do') }}</button>
                    </form>
                </article>
            @else
                <article>
                    <header>{{ __('warehouse.moves.restore_title') }}</header>
                    <form method="post" action="{{ route('warehouse.stock.restore', $unit) }}">
                        @csrf
                        <input type="hidden" name="_form" value="restore">
                        <input type="text" name="location_code" class="scan" placeholder="{{ __('warehouse.moves.to') }}" value="{{ old('_form') === 'restore' ? old('location_code') : '' }}" required>
                        <input type="text" name="reason" placeholder="{{ __('warehouse.moves.quarantine_reason') }}" value="{{ old('_form') === 'restore' ? old('reason') : '' }}" required>
                        <button type="submit" class="secondary">{{ __('warehouse.moves.restore_do') }}</button>
                    </form>
                </article>
            @endif
        </div>
    @endrole

    @role('admin|warehouse_supervisor')
        {{-- Audit 2026-09-22 INBOUND-05 (CR #141): the fast receiving forms record no dims / source — the supervisor corrects them here; the next daily snapshot carries the change into weekly storage / pallet rental. --}}
        <article id="correct">
            <header>{{ __('warehouse.stock.correct.title') }}</header>
            <p class="text-muted"><small>{{ __('warehouse.stock.correct.hint') }}</small></p>
            @error('correct')<p><mark>{{ $message }}</mark></p>@enderror
            <form method="post" action="{{ route('warehouse.stock.update', $unit) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_form" value="correct">
                <div class="grid">
                    @if ($unit->unit_type === 'pallet')
                        <label>{{ __('warehouse.stock.pallet_source') }}<select name="pallet_source"><option value="">—</option>@foreach ($palletSources as $ps)<option value="{{ $ps }}" @selected(old('_form') === 'correct' ? old('pallet_source') === $ps : $unit->pallet_source === $ps)>{{ __('warehouse.pallet_sources.'.$ps) }}</option>@endforeach</select></label>
                    @endif
                    <label>{{ __('warehouse.receiving.length') }}<input type="number" name="length_mm" min="1" value="{{ old('_form') === 'correct' ? old('length_mm') : $unit->length_mm }}"></label>
                    <label>{{ __('warehouse.receiving.width') }}<input type="number" name="width_mm" min="1" value="{{ old('_form') === 'correct' ? old('width_mm') : $unit->width_mm }}"></label>
                    <label>{{ __('warehouse.receiving.height') }}<input type="number" name="height_mm" min="1" value="{{ old('_form') === 'correct' ? old('height_mm') : $unit->height_mm }}"></label>
                    <label>{{ __('warehouse.stock.weight') }}<input type="number" name="weight_kg" step="0.001" min="0" value="{{ old('_form') === 'correct' ? old('weight_kg') : $unit->weight_kg }}"></label>
                    @if ($unit->unit_type === 'pallet')
                        <label>{{ __('warehouse.stock.pallet_class') }}<select name="pallet_class"><option value="">{{ __('warehouse.stock.correct.pallet_class_auto') }}</option>@foreach ($palletClasses as $pc)<option value="{{ $pc }}" @selected(old('_form') === 'correct' && old('pallet_class') === $pc)>{{ __('warehouse.pallet_classes.'.$pc) }}</option>@endforeach</select></label>
                    @endif
                </div>
                <div class="grid">
                    <input type="text" name="reason" placeholder="{{ __('warehouse.stock.correct.reason') }}" value="{{ old('_form') === 'correct' ? old('reason') : '' }}" required maxlength="255">
                    <button type="submit" class="secondary">{{ __('warehouse.stock.correct.submit') }}</button>
                </div>
            </form>
        </article>
    @endrole

    <h2>{{ __('warehouse.stock.ledger') }}</h2>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('warehouse.stock.time') }}</th><th>{{ __('warehouse.stock.movement') }}</th><th class="num">{{ __('warehouse.stock.qty') }}</th><th class="num">{{ __('warehouse.stock.before') }}</th><th class="num">{{ __('warehouse.stock.after') }}</th><th>{{ __('warehouse.stock.from') }} → {{ __('warehouse.stock.to') }}</th><th>{{ __('warehouse.stock.source') }}</th></tr></thead>
        <tbody>
        @foreach ($ledger as $e)
            <tr><td>{{ $e->created_at->format('Y-m-d H:i') }}</td><td>{{ __('warehouse.movement_types.'.$e->movement_type) }}</td><td class="num">{{ $e->qty }}</td><td class="num">{{ $e->qty_before }}</td><td class="num">{{ $e->qty_after }}</td><td>{{ $e->from_location_id ?? '—' }} → {{ $e->to_location_id ?? '—' }}</td><td>{{ \Illuminate\Support\Facades\Lang::has('platform.source_types.'.$e->source_type) ? __('platform.source_types.'.$e->source_type) : $e->source_type }} #{{ $e->source_id }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <h2>{{ __('warehouse.stock.reservations') }}</h2>
    <table class="dense">
        <thead><tr><th>{{ __('warehouse.stock.order') }}</th><th class="num">{{ __('warehouse.stock.qty') }}</th><th>{{ __('warehouse.stock.condition') }}</th><th>{{ __('warehouse.reservations.created_at') }}</th></tr></thead>
        <tbody>@foreach ($reservations as $r)<tr><td>{{ __('warehouse.stock.reservation_ref', ['order' => $r->order_id, 'line' => $r->order_line_id]) }}</td><td class="num">{{ $r->qty }}</td><td>{!! \App\Support\Ui\StatusBadge::render('warehouse.reservation_statuses.', $r->status) !!}</td><td>{{ $r->created_at->format('Y-m-d H:i') }}</td></tr>@endforeach</tbody>
    </table>
@endsection
