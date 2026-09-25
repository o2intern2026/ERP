{{-- The inbound context (柜号 / 柜型 / 预计到港日 / 参考号 / 备注) — CHANGE_REQUESTS #123, shared since #128 by the upload page and 手工建立入库清单;
     $defaults = what a draft filled in (old() always wins). --}}
@php($defaults = $defaults ?? [])
<article class="kv-card">
    <strong>{{ __('portal.inbound.sections.context') }}</strong>
    <p class="text-muted"><small>{{ __('portal.inbound.context_hint') }}</small></p>
    <div class="grid">
        {{-- CHANGE_REQUESTS #158: the 柜号 is no longer typed by the client — the system numbers the list (CTN-<date>-NNNN) when it is read. --}}
        <label>{{ __('portal.inbound.fields.container_no') }}<input type="text" value="{{ $defaults['container_no'] ?? __('portal.inbound.container_auto') }}" disabled aria-describedby="container-auto-hint"><small id="container-auto-hint">{{ __('portal.inbound.container_auto_hint') }}</small></label>
        <label>{{ __('portal.inbound.fields.container_size') }}
            <select name="container_size">
                <option value="">{{ __('portal.actions.select') }}</option>
                @foreach ($containerSizes as $size)<option value="{{ $size }}" @selected((string) old('container_size', $defaults['container_size'] ?? '') === $size)>{{ __('warehouse.container_sizes.'.$size) }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('portal.inbound.fields.expected_date') }}<x-date-field name="expected_date" value="{{ old('expected_date', $defaults['expected_date'] ?? '') }}" /></label>
        <label>{{ __('portal.inbound.fields.reference') }}<input type="text" name="reference" maxlength="60" value="{{ old('reference', $defaults['reference'] ?? '') }}"></label>
    </div>
    <label>{{ __('portal.inbound.fields.notes') }}<textarea name="notes" rows="2" maxlength="2000">{{ old('notes', $defaults['notes'] ?? '') }}</textarea></label>
</article>
