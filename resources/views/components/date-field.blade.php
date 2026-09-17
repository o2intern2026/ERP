{{-- Shared date field (lead request 2026-09-17, Jimmy): every date box READS dd/mm/yyyy whatever the browser language. Chromium formats a
     native <input type="date"> by the browser's own UI language and ignores lang="…" on the element (f985295 verified ineffective), so the
     display is ours: a text box showing dd/mm/yyyy, a hidden input carrying the ISO value, the native date input kept off-screen as the
     calendar picker behind the button. The hidden input is the ONLY named one, so controllers, validation ('date', 'after_or_equal:today'),
     old() and every `name="…" value="yyyy-mm-dd"` assertion keep working. layouts/app.blade.php wires every .date-field by event delegation
     (rows cloned from a <template> included); prefill scripts set the HIDDEN input's value and dispatch `new Event('change', {bubbles: true})`.
     Guarded by tests/Feature/Platform/DateFieldTest.php.

     Usage: x-date-field name="expected_date" :value="old('expected_date')" required min="…" max="…" aria-label="…" />
       :value   ISO yyyy-mm-dd (a longer string such as old('eta') = yyyy-mm-ddTHH:MM keeps its date part), a Carbon / DateTime, or null
       time     adds a native <input type="time"> beside the date; the hidden value becomes yyyy-mm-dd\THH:MM (date only while the time is empty)
       required / placeholder / autofocus / title / disabled / readonly / aria-*  → the visible text input
       min / max                                                                   → the native picker (and the typed value is checked against them)
       class                                                                       → the wrapper
       everything else (id, data-*)                                                → the hidden input --}}
@props(['name', 'value' => null, 'time' => false])
@php
    $iso = '';
    $clock = '';
    if ($value instanceof \DateTimeInterface) {
        $iso = $value->format('Y-m-d');
        $clock = $time ? $value->format('H:i') : '';
    } elseif ((is_string($value) || is_numeric($value)) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}))?/', (string) $value, $m)) {
        $iso = $m[1];
        $clock = $time ? ($m[2] ?? '') : '';
    }
    $text = $iso === '' ? '' : substr($iso, 8, 2).'/'.substr($iso, 5, 2).'/'.substr($iso, 0, 4);
    $hiddenValue = $iso === '' ? '' : ($clock === '' ? $iso : $iso.'T'.$clock);
    $textKeys = ['required', 'placeholder', 'autofocus', 'title', 'disabled', 'readonly'];
    $isText = fn ($v, string $k) => in_array($k, $textKeys, true) || str_starts_with($k, 'aria-');
    $textAttributes = $attributes->filter($isText);
    $hiddenAttributes = $attributes->except([...$textKeys, 'min', 'max', 'class'])->filter(fn ($v, string $k) => ! str_starts_with($k, 'aria-'));
@endphp
<span {{ $attributes->only('class')->class(['date-field']) }} data-invalid="{{ __('platform.date_field.invalid') }}">
    <input {{ $textAttributes->merge(['type' => 'text', 'inputmode' => 'numeric', 'placeholder' => 'dd/mm/yyyy', 'pattern' => '\d{2}/\d{2}/\d{4}', 'class' => 'date-text', 'autocomplete' => 'off', 'value' => $text]) }}>
    <input type="hidden" name="{{ $name }}" value="{{ $hiddenValue }}"{!! $hiddenAttributes->isNotEmpty() ? ' '.$hiddenAttributes : '' !!}>
    <input type="date" class="date-native" tabindex="-1" aria-hidden="true"{!! $attributes->has('min') || $attributes->has('max') ? ' '.$attributes->only(['min', 'max']) : '' !!}>
    @if ($time)
        <input type="time" class="date-time" value="{{ $clock }}" aria-label="{{ __('platform.date_field.time') }}"{!! $attributes->has('disabled') || $attributes->has('readonly') ? ' '.$attributes->only(['disabled', 'readonly']) : '' !!}>
    @endif
    <button type="button" class="date-pick secondary outline" aria-label="{{ __('platform.date_field.pick') }}" title="{{ __('platform.date_field.pick') }}"{!! $attributes->has('disabled') ? ' '.$attributes->only('disabled') : '' !!}><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><rect x="1.5" y="3" width="13" height="11.5" rx="1.5"/><path d="M1.5 6.5h13M5 1.5v3M11 1.5v3"/></svg></button>
</span>
