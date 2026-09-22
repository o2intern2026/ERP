{{-- CHANGE_REQUESTS #143 导入选项 — shared by the upload page and 手工建立入库清单: how rows become orders (按唛头 | 按收件人) and the address type of
     rows without an explicit 地址类型 (自动判断 | 住宅 | 商业). Recorded in the import context (never on a parser row); $defaults = what a draft
     chose (old() always wins). --}}
@php($defaults = $defaults ?? [])
<article class="kv-card" id="import-options">
    <strong>{{ __('portal.inbound.options.section') }}</strong>
    <div class="grid">
        <label>{{ __('portal.inbound.options.group_by') }}
            <select name="group_by">
                @foreach ($groupBys as $option)<option value="{{ $option }}" @selected((string) old('group_by', $defaults['group_by'] ?? 'mark') === $option)>{{ __('portal.inbound.options.group_by_options.'.$option) }}</option>@endforeach
            </select>
        </label>
        <label>{{ __('portal.inbound.options.address_type_default') }}
            <select name="address_type_default">
                @foreach ($addressTypeDefaults as $option)<option value="{{ $option }}" @selected((string) old('address_type_default', $defaults['address_type_default'] ?? 'auto') === $option)>{{ __('portal.inbound.options.address_type_defaults.'.$option) }}</option>@endforeach
            </select>
        </label>
    </div>
    <p class="text-muted"><small>{{ __('portal.inbound.options.group_by_hint') }}</small></p>
    <p class="text-muted"><small>{{ __('portal.inbound.options.address_type_default_hint') }}</small></p>
</article>
