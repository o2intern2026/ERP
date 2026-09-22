@extends('layouts.app')

@section('title', $card->name.' v'.$card->version)

@section('content')
    @php($editable = $card->status === 'draft' && ! $locked)
    @php($money = fn ($cents) => $cents !== null ? \App\Support\Money::cents((int) round($cents))->format() : '—')
    @php($shown = fn (string $field, $value) => match ($field) {
        'rate_cents', 'min_charge_cents' => $money($value),
        'is_poa' => $value ? __('billing.rate_cards.poa') : __('platform.common.no'),
        'pricing_mode' => $value ? __('billing.rate_cards.pricing_modes.'.$value) : '—',
        'markup_percent' => $value !== null ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%' : '—',
        'threshold_json' => $value ? json_encode($value) : '—',
        default => (string) $value,
    })
    {{-- Audit 2026-09-22 FIN-08 (CR #140): a changed cell prints old → new so the approver sees the price change without a second tab. --}}
    @php($cell = fn (array $changes, string $field, string $current) => isset($changes[$field]) ? '<s class="text-muted">'.e($shown($field, $changes[$field][0])).'</s> → <strong>'.e($current).'</strong>' : e($current))
    <p><a href="{{ route('billing.rate_cards.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $card->name }} <small>v{{ $card->version }}</small> <span class="badge" data-tone="{{ ['draft' => 'warn', 'active' => 'ok', 'superseded' => 'muted'][$card->status] }}">{{ __('billing.rate_cards.statuses.'.$card->status) }}</span> @if ($approved && $card->status === 'draft')<span class="badge" data-tone="ok">{{ __('billing.rate_cards.approved_badge') }}</span>@elseif ($pendingApproval)<span class="badge" data-tone="warn">{{ __('billing.rate_cards.pending_badge') }}</span>@endif @if ($locked)<span class="badge" data-tone="muted">{{ __('billing.rate_cards.locked_badge') }}</span>@endif</h1>
        <p>{{ $card->is_standard ? __('billing.rate_cards.standard') : $card->client?->name }} · {{ __('billing.rate_cards.effective_from') }} {{ $card->effective_from->format('Y-m-d') }} @if ($card->effective_to) → {{ $card->effective_to->format('Y-m-d') }} @endif · {{ $card->notes }}</p>
    </header>
    <div class="grid">
        <form method="post" action="{{ route('billing.rate_cards.new_version', $card) }}" class="grid">@csrf<x-date-field name="effective_from" value="{{ today()->toDateString() }}" required /><input type="text" name="notes" placeholder="{{ __('billing.rate_cards.notes') }}"><button type="submit" class="secondary">{{ __('billing.rate_cards.new_version') }}</button></form>
        @if ($card->status === 'draft')
            <div>
                @if (! $approved)<form method="post" action="{{ route('billing.rate_cards.request_activation', $card) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('billing.rate_cards.request_activation') }}</button></form>@endif
                <form method="post" action="{{ route('billing.rate_cards.activate', $card) }}" class="inline">@csrf<button type="submit" @disabled(! $approved)>{{ __('billing.rate_cards.activate') }}</button></form>
            </div>
        @endif
    </div>
    {{-- Audit 2026-09-22 FIN-08 (CR #140): once submitted the draft is frozen — what the second person approves is what goes live. --}}
    @if ($locked)
        <p class="text-muted"><small>{{ __('billing.rate_cards.locked_hint') }} <a href="{{ route('platform.approvals.index', ['type' => 'rate_card_change']) }}">{{ __('platform.approvals.title') }}</a></small></p>
    @else
        <p class="text-muted"><small>{{ __('billing.rate_cards.draft_hint') }}</small></p>
    @endif

    @if ($card->status === 'draft')
        @if ($diff === null)
            <p class="text-muted"><small>{{ __('billing.rate_cards.diff_first_version') }}</small></p>
        @else
            @php($changedTotal = $diff['counts']['added'] + $diff['counts']['changed'] + $diff['counts']['removed'])
            <p id="rate-diff-summary"><strong>{{ __('billing.rate_cards.diff_title', ['version' => $diff['previous']->version]) }}</strong>
                @if ($changedTotal === 0)
                    <span class="text-muted">{{ __('billing.rate_cards.diff_none', ['version' => $diff['previous']->version]) }}</span>
                @else
                    {{ __('billing.rate_cards.diff_counts', ['added' => $diff['counts']['added'], 'changed' => $diff['counts']['changed'], 'removed' => $diff['counts']['removed']]) }}
                    · <label class="inline"><input type="checkbox" id="rate-diff-only" data-target="rate-items"> {{ __('billing.rate_cards.diff_only') }}</label>
                @endif
                · <a href="{{ route('billing.rate_cards.show', $diff['previous']) }}">v{{ $diff['previous']->version }}</a>
            </p>
        @endif
    @endif

    <div class="overflow-auto"><table class="dense" id="rate-items">
        <thead><tr><th>{{ __('billing.charges.code') }}</th><th>{{ __('billing.rate_cards.pricing_mode') }}</th><th class="num">{{ __('billing.rate_cards.rate') }}</th><th class="num">{{ __('billing.rate_cards.min_charge') }}</th><th>{{ __('billing.rate_cards.pallet_class') }}</th><th>{{ __('billing.rate_cards.band') }}</th><th>{{ __('billing.rate_cards.zone') }} / {{ __('billing.rate_cards.service_level') }}</th><th>{{ __('billing.rate_cards.warehouse') }}</th><th>{{ __('billing.rate_cards.thresholds') }}</th>@if ($editable)<th>{{ __('platform.common.actions') }}</th>@endif</tr></thead>
        <tbody>
        @foreach ($card->items->sortBy(fn ($i) => $i->chargeCode->code) as $item)
            @php($state = $diff['items'][$item->id]['state'] ?? null)
            @php($changes = $diff['items'][$item->id]['changes'] ?? [])
            <tr @if ($state) data-diff="{{ $state }}" @endif>
                <td><code>{{ $item->chargeCode->code }}</code> @if ($state === 'added')<span class="badge" data-tone="ok">{{ __('billing.rate_cards.diff_added') }}</span>@elseif ($state === 'changed')<span class="badge" data-tone="warn">{{ __('billing.rate_cards.diff_changed') }}</span>@endif<br><small class="text-muted">{{ $item->chargeCode->customer_description }}</small></td>
                <td>{!! $cell($changes, 'pricing_mode', __('billing.rate_cards.pricing_modes.'.$item->pricing_mode)) !!} @if ($item->markup_percent !== null || isset($changes['markup_percent'])) {!! $cell($changes, 'markup_percent', $shown('markup_percent', $item->markup_percent)) !!} @endif @if (isset($changes['is_poa'])) · {!! $cell($changes, 'is_poa', $shown('is_poa', $item->is_poa)) !!} @endif</td>
                <td class="num">{!! $cell($changes, 'rate_cents', $item->is_poa ? __('billing.rate_cards.poa') : $money($item->rate_cents)) !!}</td>
                <td class="num">{!! $cell($changes, 'min_charge_cents', $money($item->min_charge_cents)) !!}</td>
                <td>{{ $item->pallet_class ? __('warehouse.pallet_classes.'.$item->pallet_class) : '—' }}</td>
                <td>{{ $item->weight_band_min !== null || $item->weight_band_max !== null ? ($item->weight_band_min ?? 0).' – '.($item->weight_band_max ?? '∞') : '—' }}</td>
                <td>{{ $item->zone ?? '—' }} / {{ $item->service_level ?? '—' }}</td>
                <td>{{ $item->warehouse_id ? ($warehouses[$item->warehouse_id] ?? '#'.$item->warehouse_id) : __('billing.rate_cards.all_warehouses') }}</td>
                <td><small><code>{!! $cell($changes, 'threshold_json', $item->threshold_json ? json_encode($item->threshold_json) : '') !!}</code></small></td>
                @if ($editable)
                    <td><form method="post" action="{{ route('billing.rate_items.update', $item) }}" class="inline">@csrf<input type="number" step="0.01" min="0" name="rate" value="{{ $item->rate_cents !== null ? $item->rate_cents / 100 : '' }}" style="width:6rem" placeholder="{{ __('billing.rate_cards.rate') }}"><input type="number" step="0.01" min="0" name="min_charge" value="{{ $item->min_charge_cents !== null ? $item->min_charge_cents / 100 : '' }}" style="width:6rem" placeholder="{{ __('billing.rate_cards.min_charge') }}"><label class="inline"><input type="checkbox" name="is_poa" value="1" @checked($item->is_poa)> {{ __('billing.rate_cards.poa') }}</label><input type="hidden" name="pricing_mode" value="{{ $item->pricing_mode }}"><input type="hidden" name="markup_percent" value="{{ $item->markup_percent }}"><input type="hidden" name="pallet_class" value="{{ $item->pallet_class }}"><input type="hidden" name="weight_band_min" value="{{ $item->weight_band_min }}"><input type="hidden" name="weight_band_max" value="{{ $item->weight_band_max }}"><input type="hidden" name="zone" value="{{ $item->zone }}"><input type="hidden" name="service_level" value="{{ $item->service_level }}"><input type="hidden" name="warehouse_id" value="{{ $item->warehouse_id }}"><input type="hidden" name="threshold_json" value="{{ $item->threshold_json ? json_encode($item->threshold_json) : '' }}"><button type="submit" class="secondary outline">{{ __('platform.common.save') }}</button></form></td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($diff !== null && $diff['removed']->isNotEmpty())
        <h3>{{ __('billing.rate_cards.diff_removed_title', ['version' => $diff['previous']->version]) }}</h3>
        <div class="overflow-auto"><table class="dense" id="rate-items-removed">
            <thead><tr><th>{{ __('billing.charges.code') }}</th><th>{{ __('billing.rate_cards.pricing_mode') }}</th><th class="num">{{ __('billing.rate_cards.rate') }}</th><th class="num">{{ __('billing.rate_cards.min_charge') }}</th><th>{{ __('billing.rate_cards.pallet_class') }}</th><th>{{ __('billing.rate_cards.band') }}</th><th>{{ __('billing.rate_cards.zone') }} / {{ __('billing.rate_cards.service_level') }}</th><th>{{ __('billing.rate_cards.warehouse') }}</th><th>{{ __('billing.rate_cards.thresholds') }}</th></tr></thead>
            <tbody>
            @foreach ($diff['removed']->sortBy(fn ($i) => $i->chargeCode->code) as $item)
                <tr data-diff="removed">
                    <td><code>{{ $item->chargeCode->code }}</code> <span class="badge" data-tone="danger">{{ __('billing.rate_cards.diff_removed') }}</span><br><small class="text-muted">{{ $item->chargeCode->customer_description }}</small></td>
                    <td>{{ __('billing.rate_cards.pricing_modes.'.$item->pricing_mode) }} @if ($item->markup_percent !== null) {{ $shown('markup_percent', $item->markup_percent) }} @endif</td>
                    <td class="num">{{ $item->is_poa ? __('billing.rate_cards.poa') : $money($item->rate_cents) }}</td>
                    <td class="num">{{ $money($item->min_charge_cents) }}</td>
                    <td>{{ $item->pallet_class ? __('warehouse.pallet_classes.'.$item->pallet_class) : '—' }}</td>
                    <td>{{ $item->weight_band_min !== null || $item->weight_band_max !== null ? ($item->weight_band_min ?? 0).' – '.($item->weight_band_max ?? '∞') : '—' }}</td>
                    <td>{{ $item->zone ?? '—' }} / {{ $item->service_level ?? '—' }}</td>
                    <td>{{ $item->warehouse_id ? ($warehouses[$item->warehouse_id] ?? '#'.$item->warehouse_id) : __('billing.rate_cards.all_warehouses') }}</td>
                    <td><small><code>{{ $item->threshold_json ? json_encode($item->threshold_json) : '' }}</code></small></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    @if ($editable)
        {{-- Audit 2026-09-10: the add-item form reopens with what was typed after a validation error. --}}
        <details{{ $errors->any() ? ' open' : '' }}>
            <summary role="button" class="secondary outline">{{ __('billing.rate_cards.add_item') }}</summary>
            <form method="post" action="{{ route('billing.rate_cards.items.store', $card) }}">
                @csrf
                <div class="grid">
                    <select name="charge_code_id" required>@foreach ($codes as $code)<option value="{{ $code->id }}" @selected((int) old('charge_code_id') === $code->id)>{{ $code->code }} · {{ $code->customer_description }}</option>@endforeach</select>
                    <select name="pricing_mode">@foreach ($pricingModes as $m)<option value="{{ $m }}" @selected(old('pricing_mode', 'fixed') === $m)>{{ __('billing.rate_cards.pricing_modes.'.$m) }}</option>@endforeach</select>
                    <input type="number" step="0.01" min="0" name="rate" placeholder="{{ __('billing.rate_cards.rate') }}" value="{{ old('rate') }}"><input type="number" step="0.01" min="0" name="markup_percent" placeholder="{{ __('billing.rate_cards.markup') }}" value="{{ old('markup_percent') }}">
                </div>
                <div class="grid">
                    <input type="number" step="0.01" min="0" name="min_charge" placeholder="{{ __('billing.rate_cards.min_charge') }}" value="{{ old('min_charge') }}">
                    <select name="pallet_class"><option value="">{{ __('billing.rate_cards.pallet_class') }}</option>@foreach ($palletClasses as $pc)<option value="{{ $pc }}" @selected(old('pallet_class') === $pc)>{{ __('warehouse.pallet_classes.'.$pc) }}</option>@endforeach</select>
                    <input type="number" step="0.01" min="0" name="weight_band_min" placeholder="{{ __('billing.rate_cards.band_min') }}" value="{{ old('weight_band_min') }}"><input type="number" step="0.01" min="0" name="weight_band_max" placeholder="{{ __('billing.rate_cards.band_max') }}" value="{{ old('weight_band_max') }}">
                    <input type="text" name="zone" placeholder="{{ __('billing.rate_cards.zone') }}" value="{{ old('zone') }}"><input type="text" name="service_level" placeholder="{{ __('billing.rate_cards.service_level') }}" value="{{ old('service_level') }}">
                    <select name="warehouse_id" aria-label="{{ __('billing.rate_cards.warehouse') }}"><option value="">{{ __('billing.rate_cards.all_warehouses') }}</option>@foreach ($warehouses as $id => $whCode)<option value="{{ $id }}" @selected((int) old('warehouse_id') === (int) $id)>{{ $whCode }}</option>@endforeach</select>
                </div>
                <p class="text-muted"><small>{{ __('billing.rate_cards.tier_hint') }}</small></p>
                <input type="text" name="threshold_json" placeholder="{{ __('billing.rate_cards.thresholds_placeholder') }}" value="{{ old('threshold_json') }}" @error('threshold_json') aria-invalid="true" @enderror>
                <label><input type="checkbox" name="is_poa" value="1" @checked(old('is_poa'))> {{ __('billing.rate_cards.poa') }}</label>
                <button type="submit" class="secondary">{{ __('billing.rate_cards.add_item') }}</button>
            </form>
        </details>
    @endif
    <script>
        // FIN-08: 只看改动 hides the rows the draft leaves unchanged (rows carry data-diff = added | changed | same).
        var diffOnly = document.getElementById('rate-diff-only');
        if (diffOnly) {
            diffOnly.addEventListener('change', function () {
                document.querySelectorAll('#rate-items tbody tr[data-diff="same"]').forEach(function (row) { row.hidden = diffOnly.checked; });
            });
        }
    </script>
@endsection
