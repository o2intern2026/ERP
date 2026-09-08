@extends('layouts.app')

@section('title', __('portal.stock.title'))

@section('content')
    <header>
        <h1>{{ __('portal.stock.title') }}</h1>
        <p class="text-muted"><small>{{ __('portal.stock.hint') }}</small></p>
    </header>

    <style>
        /* Item 1 (tester feedback): search box + 货物状态 + 可用性 + 筛选 on one row on desktop (wraps only on phones). Inline until
           app.css gets shared classes (CHANGE_REQUESTS #80). */
        form.filter-row { display: flex; flex-wrap: nowrap; gap: .5rem; align-items: center; margin-bottom: 1rem; }
        form.filter-row > * { margin-bottom: 0; }
        form.filter-row input[type="search"] { flex: 1 1 14rem; min-width: 0; }
        form.filter-row select { flex: 0 0 auto; width: auto; min-width: 9rem; }
        form.filter-row button { flex: 0 0 auto; width: auto; }
        @media (max-width: 640px) { form.filter-row { flex-wrap: wrap; } form.filter-row input[type="search"] { flex-basis: 100%; } }
    </style>
    <form method="get" class="filter-row">
        <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('portal.stock.search') }}" aria-label="{{ __('portal.stock.search') }}">
        <select name="condition" aria-label="{{ __('portal.stock.filters.condition') }}">
            <option value="">{{ __('portal.stock.filters.all_conditions') }}</option>
            @foreach ($conditionFilters as $value)
                <option value="{{ $value }}" @selected(($filters['condition'] ?? '') === $value)>{{ __('portal.stock.filters.conditions.'.$value) }}</option>
            @endforeach
        </select>
        <select name="availability" aria-label="{{ __('portal.stock.filters.availability') }}">
            <option value="">{{ __('portal.stock.filters.all_availability') }}</option>
            @foreach ($availabilityFilters as $value)
                <option value="{{ $value }}" @selected(($filters['availability'] ?? '') === $value)>{{ __('portal.stock.filters.availabilities.'.$value) }}</option>
            @endforeach
        </select>
        <button type="submit" class="secondary">{{ __('portal.actions.filter') }}</button>
    </form>

    @if ($rows->isEmpty())
        <p>{{ __('portal.stock.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead><tr>
                    <th>{{ __('portal.stock.fields.consignment_mark') }}</th>
                    <th>{{ __('portal.stock.fields.description') }}</th>
                    <th>{{ __('portal.stock.fields.asn_no') }}</th>
                    <th>{{ __('portal.stock.fields.location_type') }}</th>
                    <th>{{ __('portal.stock.fields.condition') }}</th>
                    <th class="num">{{ __('portal.stock.fields.pallets') }}</th>
                    <th class="num">{{ __('portal.stock.fields.on_hand') }}</th>
                    <th class="num">{{ __('portal.stock.fields.reserved') }}</th>
                    <th class="num">{{ __('portal.stock.fields.available') }}</th>
                    <th class="num">{{ __('portal.stock.fields.inbound') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><strong>{{ $row->consignment_mark }}</strong>@if ($row->fba_reference)<br><small class="text-muted">{{ $row->fba_reference }}</small>@endif</td>
                            <td>{{ $row->description }}</td>
                            <td>{{ $row->asn_no }}</td>
                            <td>{{ $row->location_type ? __('portal.stock.location_types.'.$row->location_type) : __('portal.not_provided') }}
                                @unless ($row->putaway_completed)<br><small class="text-muted">{{ __('portal.stock.not_put_away') }}</small>@endunless
                            </td>
                            <td><span class="badge" data-tone="{{ $row->condition === 'good' ? 'ok' : 'warn' }}">{{ __('portal.stock.conditions.'.$row->condition) }}</span></td>
                            <td class="num">{{ $row->pallets }}</td>
                            <td class="num">{{ $row->qty_on_hand }}</td>
                            <td class="num">{{ $row->qty_reserved }}</td>
                            <td class="num"><strong>{{ $row->qty_available }}</strong></td>
                            <td class="num">{{ $row->qty_inbound }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5">{{ __('portal.stock.totals', ['lines' => $rows->unique('asn_line_id')->count(), 'units' => $totals['units']]) }}</th>
                        <th class="num">{{ $totals['pallets'] }}</th>
                        <th class="num">{{ $totals['qty_on_hand'] }}</th>
                        <th class="num">{{ $totals['qty_reserved'] }}</th>
                        <th class="num">{{ $totals['qty_available'] }}</th>
                        <th class="num">{{ $totals['qty_inbound'] }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
@endsection
