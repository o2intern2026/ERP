{{-- Item 5 (tester feedback): package type as a dropdown (Chinese + code, OrderEnums::PACKAGE_TYPES). A stored value outside the
     list (API / Excel / PDF / ASN data) is kept as an extra option so editing an old row never silently changes it. --}}
@php
    $value = $value ?? 'carton';
    $known = in_array($value, \App\Modules\Orders\OrderEnums::PACKAGE_TYPES, true);
@endphp
<select name="{{ $name }}" class="{{ $class ?? '' }}" aria-label="{{ $ariaLabel ?? __('orders.fields.package_type') }}" @required($required ?? false)>
    @foreach (\App\Modules\Orders\OrderEnums::PACKAGE_TYPES as $type)
        <option value="{{ $type }}" @selected($value === $type)>{{ __('orders.package_types.'.$type) }}</option>
    @endforeach
    @if (! $known && $value !== null && $value !== '')
        <option value="{{ $value }}" selected>{{ $value }}</option>
    @endif
</select>
