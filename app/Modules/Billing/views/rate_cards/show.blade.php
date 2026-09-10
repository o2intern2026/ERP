@extends('layouts.app')

@section('title', $card->name.' v'.$card->version)

@section('content')
    <p><a href="{{ route('billing.rate_cards.index') }}">← {{ __('platform.common.back') }}</a></p>
    <header>
        <h1>{{ $card->name }} <small>v{{ $card->version }}</small> <span class="badge" data-tone="{{ ['draft' => 'warn', 'active' => 'ok', 'superseded' => 'muted'][$card->status] }}">{{ __('billing.rate_cards.statuses.'.$card->status) }}</span> @if ($approved && $card->status === 'draft')<span class="badge" data-tone="ok">{{ __('billing.rate_cards.approved_badge') }}</span>@elseif ($pendingApproval)<span class="badge" data-tone="warn">{{ __('billing.rate_cards.pending_badge') }}</span>@endif</h1>
        <p>{{ $card->is_standard ? __('billing.rate_cards.standard') : $card->client?->name }} · {{ __('billing.rate_cards.effective_from') }} {{ $card->effective_from->format('Y-m-d') }} @if ($card->effective_to) → {{ $card->effective_to->format('Y-m-d') }} @endif · {{ $card->notes }}</p>
    </header>
    <div class="grid">
        <form method="post" action="{{ route('billing.rate_cards.new_version', $card) }}" class="grid">@csrf<input type="date" name="effective_from" value="{{ today()->toDateString() }}" required><input type="text" name="notes" placeholder="{{ __('billing.rate_cards.notes') }}"><button type="submit" class="secondary">{{ __('billing.rate_cards.new_version') }}</button></form>
        @if ($card->status === 'draft')
            <div>
                @if (! $approved)<form method="post" action="{{ route('billing.rate_cards.request_activation', $card) }}" class="inline">@csrf<button type="submit" class="secondary">{{ __('billing.rate_cards.request_activation') }}</button></form>@endif
                <form method="post" action="{{ route('billing.rate_cards.activate', $card) }}" class="inline">@csrf<button type="submit" @disabled(! $approved)>{{ __('billing.rate_cards.activate') }}</button></form>
            </div>
        @endif
    </div>
    <p class="text-muted"><small>{{ __('billing.rate_cards.draft_hint') }}</small></p>

    <div class="overflow-auto"><table class="dense">
        <thead><tr><th>{{ __('billing.charges.code') }}</th><th>{{ __('billing.rate_cards.pricing_mode') }}</th><th class="num">{{ __('billing.rate_cards.rate') }}</th><th class="num">{{ __('billing.rate_cards.min_charge') }}</th><th>{{ __('billing.rate_cards.pallet_class') }}</th><th>{{ __('billing.rate_cards.band') }}</th><th>{{ __('billing.rate_cards.zone') }} / {{ __('billing.rate_cards.service_level') }}</th><th>{{ __('billing.rate_cards.thresholds') }}</th>@if ($card->status === 'draft')<th>{{ __('platform.common.actions') }}</th>@endif</tr></thead>
        <tbody>
        @foreach ($card->items->sortBy(fn ($i) => $i->chargeCode->code) as $item)
            <tr>
                <td><code>{{ $item->chargeCode->code }}</code><br><small class="text-muted">{{ $item->chargeCode->customer_description }}</small></td>
                <td>{{ __('billing.rate_cards.pricing_modes.'.$item->pricing_mode) }} @if ($item->markup_percent !== null) {{ $item->markup_percent }}% @endif</td>
                <td class="num">{{ $item->is_poa ? __('billing.rate_cards.poa') : ($item->rate_cents !== null ? \App\Support\Money::cents((int) round($item->rate_cents))->format() : '—') }}</td>
                <td class="num">{{ $item->min_charge_cents !== null ? \App\Support\Money::cents((int) round($item->min_charge_cents))->format() : '—' }}</td>
                <td>{{ $item->pallet_class ? __('warehouse.pallet_classes.'.$item->pallet_class) : '—' }}</td>
                <td>{{ $item->weight_band_min !== null || $item->weight_band_max !== null ? ($item->weight_band_min ?? 0).' – '.($item->weight_band_max ?? '∞') : '—' }}</td>
                <td>{{ $item->zone ?? '—' }} / {{ $item->service_level ?? '—' }}</td>
                <td><small><code>{{ $item->threshold_json ? json_encode($item->threshold_json) : '' }}</code></small></td>
                @if ($card->status === 'draft')
                    <td><form method="post" action="{{ route('billing.rate_items.update', $item) }}" class="inline">@csrf<input type="number" step="0.01" min="0" name="rate" value="{{ $item->rate_cents !== null ? $item->rate_cents / 100 : '' }}" style="width:6rem" placeholder="{{ __('billing.rate_cards.rate') }}"><input type="number" step="0.01" min="0" name="min_charge" value="{{ $item->min_charge_cents !== null ? $item->min_charge_cents / 100 : '' }}" style="width:6rem" placeholder="{{ __('billing.rate_cards.min_charge') }}"><label class="inline"><input type="checkbox" name="is_poa" value="1" @checked($item->is_poa)> {{ __('billing.rate_cards.poa') }}</label><input type="hidden" name="pricing_mode" value="{{ $item->pricing_mode }}"><input type="hidden" name="markup_percent" value="{{ $item->markup_percent }}"><input type="hidden" name="pallet_class" value="{{ $item->pallet_class }}"><input type="hidden" name="weight_band_min" value="{{ $item->weight_band_min }}"><input type="hidden" name="weight_band_max" value="{{ $item->weight_band_max }}"><input type="hidden" name="zone" value="{{ $item->zone }}"><input type="hidden" name="service_level" value="{{ $item->service_level }}"><input type="hidden" name="threshold_json" value="{{ $item->threshold_json ? json_encode($item->threshold_json) : '' }}"><button type="submit" class="secondary outline">{{ __('platform.common.save') }}</button></form></td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($card->status === 'draft')
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
                </div>
                <input type="text" name="threshold_json" placeholder="{{ __('billing.rate_cards.thresholds_placeholder') }}" value="{{ old('threshold_json') }}" @error('threshold_json') aria-invalid="true" @enderror>
                <label><input type="checkbox" name="is_poa" value="1" @checked(old('is_poa'))> {{ __('billing.rate_cards.poa') }}</label>
                <button type="submit" class="secondary">{{ __('billing.rate_cards.add_item') }}</button>
            </form>
        </details>
    @endif
@endsection
