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
               {{ __('warehouse.stock.on_hand') }} {{ $unit->qty_on_hand }} · {{ __('warehouse.stock.reserved') }} {{ $unit->qty_reserved }} · {{ __('warehouse.stock.available') }} {{ $unit->isAllocatable() ? $unit->availableQty() : 0 }}<br>
               {{ __('warehouse.stock.condition') }}: {{ __('warehouse.conditions.'.$unit->condition) }} · {{ __('warehouse.stock.putaway') }}: {{ $unit->putaway_completed ? __('platform.common.yes') : __('platform.common.no') }}</p>
        </article>
        @if ($unit->unit_type === 'pallet')
            <article>
                <p>{{ __('warehouse.stock.dims') }}: {{ $unit->length_mm }} × {{ $unit->width_mm }} × {{ $unit->height_mm }} · {{ __('warehouse.stock.weight') }}: {{ $unit->weight_kg }}<br>
                   {{ __('warehouse.stock.pallet_class') }}: {{ $unit->pallet_class ? __('warehouse.pallet_classes.'.$unit->pallet_class) : 'POA' }} · {{ __('warehouse.stock.pallet_source') }}: {{ $unit->pallet_source ? __('warehouse.pallet_sources.'.$unit->pallet_source) : '—' }}</p>
            </article>
        @endif
    </div>
     ($unit->condition !== 'good')
        <p><strong>{{ __('warehouse.moves.condition_reason') }}:</strong> {{ $unit->condition_reason }} <small class="text-muted">{{ $unit->condition_changed_at?->format('Y-m-d H:i') }}</small></p>
    
    <p><a href="{{ route('warehouse.labels.units', ['ids' => [$unit->id]]) }}" target="_blank">{{ __('warehouse.labels.units') }}</a></p>

    ('admin|warehouse_supervisor|warehouse_operator')
        <div class="grid">
            <article>
                <header>{{ __('warehouse.moves.title') }}</header>
                <form method="post" action="{{ route('warehouse.stock.move', $unit) }}">
                    
                    <input type="text" name="location_code" class="scan" placeholder="{{ __('warehouse.moves.to') }}" required>
                    <input type="text" name="reason" placeholder="{{ __('warehouse.moves.reason') }}">
                    <button type="submit" class="secondary">{{ __('warehouse.moves.do') }}</button>
                </form>
            </article>
             ($unit->condition === 'good')
                <article>
                    <header>{{ __('warehouse.moves.quarantine_title') }}</header>
                    <form method="post" action="{{ route('warehouse.stock.quarantine', $unit) }}" enctype="multipart/form-data">
                        
                        <select name="condition"><option value="damaged">{{ __('warehouse.conditions.damaged') }}</option><option value="quarantine">{{ __('warehouse.conditions.quarantine') }}</option></select>
                        <input type="text" name="reason" placeholder="{{ __('warehouse.moves.quarantine_reason') }}" required>
                        <label>{{ __('warehouse.moves.photos') }}<input type="file" name="photos[]" accept="image/*" multiple capture="environment"></label>
                        <button type="submit" class="secondary">{{ __('warehouse.moves.quarantine_do') }}</button>
                    </form>
                </article>
            
                <article>
                    <header>{{ __('warehouse.moves.restore_title') }}</header>
                    <form method="post" action="{{ route('warehouse.stock.restore', $unit) }}">
                        
                        <input type="text" name="location_code" class="scan" placeholder="{{ __('warehouse.moves.to') }}" required>
                        <input type="text" name="reason" placeholder="{{ __('warehouse.moves.quarantine_reason') }}" required>
                        <button type="submit" class="secondary">{{ __('warehouse.moves.restore_do') }}</button>
                    </form>
                </article>
            
        </div>
    

    <h2>{{ __('warehouse.stock.ledger') }}</h2>
    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('warehouse.stock.time') }}</th><th>{{ __('warehouse.stock.movement') }}</th><th class="num">{{ __('warehouse.stock.qty') }}</th><th class="num">{{ __('warehouse.stock.before') }}</th><th class="num">{{ __('warehouse.stock.after') }}</th><th>{{ __('warehouse.stock.from') }} → {{ __('warehouse.stock.to') }}</th><th>{{ __('warehouse.stock.source') }}</th></tr></thead>
        <tbody>
        @foreach ($ledger as $e)
            <tr><td>{{ $e->created_at->format('Y-m-d H:i') }}</td><td>{{ __('warehouse.movement_types.'.$e->movement_type) }}</td><td class="num">{{ $e->qty }}</td><td class="num">{{ $e->qty_before }}</td><td class="num">{{ $e->qty_after }}</td><td>{{ $e->from_location_id ?? '—' }} → {{ $e->to_location_id ?? '—' }}</td><td>{{ $e->source_type }} #{{ $e->source_id }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    <h2>{{ __('warehouse.stock.reservations') }}</h2>
    <table class="dense">
        <thead><tr><th>{{ __('warehouse.stock.order') }}</th><th class="num">{{ __('warehouse.stock.qty') }}</th><th>{{ __('warehouse.stock.condition') }}</th><th>{{ __('warehouse.reservations.created_at') }}</th></tr></thead>
        <tbody>@foreach ($reservations as $r)<tr><td>#{{ $r->order_id }} / line {{ $r->order_line_id }}</td><td class="num">{{ $r->qty }}</td><td>{{ $r->status }}</td><td>{{ $r->created_at->format('Y-m-d H:i') }}</td></tr>@endforeach</tbody>
    </table>
@endsection
