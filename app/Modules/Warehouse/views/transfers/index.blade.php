@extends('layouts.app')

@section('title', __('warehouse.transfers.title'))

@section('content')
    <h1>{{ __('warehouse.transfers.title') }}</h1>
    <p class="text-muted"><small>{{ __('warehouse.transfers.hint') }}</small></p>

    @role('admin|warehouse_supervisor|dispatcher')
        {{-- CHANGE_REQUESTS #167: one form — who, from, to, who pays, the codes (scan gun friendly: one pallet / unit code per line). --}}
        <details{{ $errors->any() ? ' open' : '' }}>
            <summary role="button" class="secondary outline">{{ __('warehouse.transfers.create') }}</summary>
            <form method="post" action="{{ route('warehouse.transfers.store') }}" id="transfer-create">
                @csrf
                <div class="grid">
                    <label>{{ __('warehouse.transfers.client') }}
                        <select name="client_id" required>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id') === $c->id)>{{ $c->name }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('warehouse.transfers.from') }}
                        <select name="from_warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('from_warehouse_id', $currentWarehouseId ?? $warehouses->first()?->id) === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
                    </label>
                    <label>{{ __('warehouse.transfers.to') }}
                        <select name="to_warehouse_id" required>@foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected((int) old('to_warehouse_id') === $w->id)>{{ $w->code }} · {{ $w->name }}</option>@endforeach</select>
                    </label>
                </div>
                <fieldset>
                    <legend>{{ __('warehouse.transfers.charge_to') }}</legend>
                    @foreach ($chargeTos as $c)
                        <label><input type="radio" name="charge_to" value="{{ $c }}" @checked(old('charge_to', 'internal') === $c)> {{ __('warehouse.transfers.charge_tos.'.$c) }} <small class="text-muted">{{ __('warehouse.transfers.charge_to_hints.'.$c) }}</small></label>
                    @endforeach
                </fieldset>
                <label>{{ __('warehouse.transfers.codes') }}
                    <textarea name="codes" rows="5" class="scan" placeholder="{{ __('warehouse.transfers.codes_placeholder') }}" required>{{ old('codes') }}</textarea>
                    <small class="text-muted">{{ __('warehouse.transfers.codes_hint') }}</small>
                </label>
                @error('codes')<p><mark>{{ $message }}</mark></p>@enderror
                <label>{{ __('warehouse.transfers.notes') }}<input type="text" name="notes" maxlength="255" value="{{ old('notes') }}"></label>
                <button type="submit">{{ __('warehouse.transfers.submit') }}</button>
            </form>
        </details>
    @endrole

    <form method="get" action="{{ route('warehouse.transfers.index') }}" class="grid" style="align-items:end">
        <label>{{ __('warehouse.transfers.status') }}
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('platform.jobs.all') }}</option>
                @foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('warehouse.transfers.statuses.'.$s) }}</option>@endforeach
            </select>
        </label>
    </form>

    @if ($transfers->isEmpty())
        <p>{{ __('warehouse.transfers.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.transfers.number') }}</th><th>{{ __('warehouse.transfers.client') }}</th><th>{{ __('warehouse.transfers.route') }}</th><th>{{ __('warehouse.transfers.charge_to') }}</th><th>{{ __('warehouse.transfers.status') }}</th><th class="num">{{ __('warehouse.transfers.lines') }}</th><th class="num">{{ __('warehouse.transfers.cartons') }}</th><th>{{ __('warehouse.transfers.dispatched_at') }}</th><th>{{ __('warehouse.transfers.received_at') }}</th></tr></thead>
            <tbody>
            @foreach ($transfers as $t)
                <tr>
                    <td><a href="{{ route('warehouse.transfers.show', $t) }}"><code>{{ $t->transfer_no }}</code></a></td>
                    <td>{{ $t->client?->name }}</td>
                    <td>{{ $t->fromWarehouse?->code }} → {{ $t->toWarehouse?->code }}</td>
                    <td>{{ __('warehouse.transfers.charge_tos.'.$t->charge_to) }}</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('warehouse.transfers.statuses.', $t->status) !!}</td>
                    <td class="num">{{ $t->lines_count }}</td>
                    <td class="num">{{ (int) $t->lines_sum_qty }}</td>
                    <td>{{ $t->dispatched_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>{{ $t->received_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $transfers->links() }}
    @endif
@endsection
