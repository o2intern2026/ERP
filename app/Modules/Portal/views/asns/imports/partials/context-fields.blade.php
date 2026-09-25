{{-- The inbound context (柜号 / 柜型 / 预计到港日 / 参考号 / 备注) — CHANGE_REQUESTS #123, shared since #128 by the upload page and 手工建立入库清单;
     $defaults = what a draft filled in (old() always wins). --}}
@php($defaults = $defaults ?? [])
<article class="kv-card">
    <strong>{{ __('portal.inbound.sections.context') }}</strong>
    <p class="text-muted"><small>{{ __('portal.inbound.context_hint') }}</small></p>
    <div class="grid">
        {{-- CHANGE_REQUESTS #158 (revised 2026-09-25): the box opens with a visibly generated 柜号 (CTN-XXXXXX, $defaults) the client may overwrite
             with the shipping line's number; 重新生成 fetches another; left blank, the list is numbered when it is read. --}}
        <label>{{ __('portal.inbound.fields.container_no') }}
            <span style="display:flex;gap:.4rem;align-items:center">
                <input type="text" name="container_no" id="container-no" maxlength="20" value="{{ old('container_no', $defaults['container_no'] ?? '') }}" placeholder="MSKU1234567" style="text-transform:uppercase;margin:0">
                <button type="button" class="secondary outline" id="container-no-generate" data-url="{{ route('portal.asns.imports.container_no') }}" style="width:auto;white-space:nowrap;margin:0;padding:.35rem .7rem">{{ __('portal.inbound.container_generate') }}</button>
            </span>
            <small>{{ __('portal.inbound.container_auto_hint') }}</small>
        </label>
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
<script>
    (function () {
        // CHANGE_REQUESTS #158 (revised): 重新生成 fetches another generated number and puts it in the box; the box stays editable.
        var btn = document.getElementById('container-no-generate'), box = document.getElementById('container-no');
        if (!btn || !box) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            fetch(btn.dataset.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d && d.container_no) { box.value = d.container_no; box.dispatchEvent(new Event('change', { bubbles: true })); } })
                .catch(function () {})
                .finally(function () { btn.disabled = false; box.focus(); });
        });
    })();
</script>
