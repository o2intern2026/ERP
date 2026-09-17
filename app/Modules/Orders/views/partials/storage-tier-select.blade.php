{{-- CHANGE_REQUESTS #129: the storage TIER of one goods line (标准 default / 底层) — never a physical bin; the supervisor still picks the
     location at putaway (#126). Shared by the order forms (goods-line-row), the draft line add / edit forms and any caller that names it. --}}
@php
    $value = in_array($value ?? null, \App\Support\Enums::STORAGE_TIERS, true) ? $value : 'standard';
@endphp
<select name="{{ $name }}" class="{{ $class ?? '' }}" aria-label="{{ $ariaLabel ?? __('orders.lines.storage_tier') }}">
    @foreach (\App\Support\Enums::STORAGE_TIERS as $tier)
        <option value="{{ $tier }}" @selected($value === $tier)>{{ __('orders.lines.storage_tiers.'.$tier) }}</option>
    @endforeach
</select>
