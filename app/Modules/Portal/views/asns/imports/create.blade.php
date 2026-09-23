@extends('layouts.app')

@section('title', __('portal.inbound.title'))

@section('content')
    <p><a href="{{ route('portal.orders.create') }}">← {{ __('portal.actions.create') }}</a> · <a href="{{ route('portal.asns.index') }}">{{ __('portal.asns.title') }}</a> · <a href="{{ route('portal.asns.imports.index') }}">{{ __('portal.inbound.list_title') }}</a> · <a href="{{ route('portal.asns.imports.manual.create') }}">{{ __('portal.inbound.manual.button') }}</a></p>
    <header>
        <h1>{{ __('portal.inbound.title') }}</h1>
        {{-- CHANGE_REQUESTS #144: the hint follows the chosen type (the from_stock one names the ASN step, the pickup one the collection). --}}
        <p class="text-muted" data-only="from_stock"><small>{{ __('portal.inbound.hint') }}</small></p>
        <p class="text-muted" data-only="pickup_deliver" hidden><small>{{ __('portal.inbound.hint_pickup') }}</small></p>
    </header>

    @if ($errors->any())
        <article><strong>{{ __('portal.validation.heading') }}</strong><ul>@foreach (array_unique($errors->all()) as $error)<li>{{ $error }}</li>@endforeach</ul></article>
    @endif

    <form method="post" action="{{ route('portal.asns.imports.store') }}" enctype="multipart/form-data">
        @csrf
        {{-- CHANGE_REQUESTS #144: the order type the list produces — 新建订单 links here with one preselected (预报入库 keeps 库存出库配送);
             the inbound blocks and the pickup block below follow the choice (the one not chosen is hidden AND disabled, so nothing of it submits). --}}
        <article class="kv-card" id="order-type-choice">
            <strong>{{ __('portal.inbound.order_type') }}</strong>
            @foreach ($orderTypes as $type)
                <label style="margin:.3rem 0"><input type="radio" name="order_type" value="{{ $type }}" @checked(old('order_type', $orderType) === $type)> <strong>{{ __('orders.types.'.$type) }}</strong> <small class="text-muted">{{ __('portal.inbound.order_types.'.$type) }}</small></label>
            @endforeach
        </article>

        <fieldset id="from-stock-fields">
            @include('portal::asns.imports.partials.context-fields')
            {{-- CHANGE_REQUESTS #125 到仓方式 — the fieldset and its toggle live in the partial shared with 手工建立入库清单 (#128). --}}
            @include('portal::asns.imports.partials.collection-fields')
        </fieldset>
        <fieldset id="pickup-deliver-fields" hidden disabled>
            @include('portal::asns.imports.partials.pickup-fields')
        </fieldset>
        {{-- CHANGE_REQUESTS #143 导入选项 (分组规则 / 地址类型默认) — shared with 手工建立入库清单. --}}
        @include('portal::asns.imports.partials.import-options')

        <article class="kv-card">
            <strong>{{ __('portal.inbound.sections.file') }}</strong>
            <label>{{ __('portal.inbound.fields.file') }}<input type="file" name="manifest" accept=".csv,.xlsx,.xls" required></label>
            <p><small><a href="{{ route('portal.asns.imports.template') }}">{{ __('portal.inbound.template') }}</a> · {{ __('portal.inbound.template_hint') }}</small></p>
            <p class="text-muted"><small>{{ implode(' · ', $templateHeaders) }}</small></p>
            <p class="text-muted"><small>{{ __('portal.inbound.consolidation_hint') }}</small></p>{{-- CHANGE_REQUESTS #143: the English consolidation list uploads as is --}}
            <p class="text-muted" data-only="from_stock"><small>{{ __('portal.inbound.defaults_hint') }}</small></p>
            <p class="text-muted" data-only="from_stock"><small>{{ __('portal.inbound.storage_tier_hint') }}</small></p>
            <p class="text-muted" data-only="pickup_deliver" hidden><small>{{ __('portal.inbound.pickup.weight_hint') }}</small></p>{{-- CHANGE_REQUESTS #144 --}}
            <p class="text-muted"><small>{{ __('portal.inbound.address_type_hint') }}</small></p>{{-- CHANGE_REQUESTS #136 --}}
            <p class="text-muted"><small>{{ __('portal.inbound.pitfalls') }}</small></p>
        </article>

        <button type="submit">{{ __('portal.inbound.actions.upload') }}</button>
        <a class="secondary" role="button" href="{{ route('portal.orders.create') }}">{{ __('portal.actions.back') }}</a>
    </form>

    <script>
        (function () {
            var radios = Array.prototype.slice.call(document.querySelectorAll('input[name="order_type"]'));
            var stock = document.getElementById('from-stock-fields');
            var pickup = document.getElementById('pickup-deliver-fields');
            function toggle() {
                var pure = radios.some(function (r) { return r.checked && r.value === 'pickup_deliver'; });
                stock.hidden = pure; stock.disabled = pure;
                pickup.hidden = !pure; pickup.disabled = !pure;
                Array.prototype.forEach.call(document.querySelectorAll('[data-only]'), function (el) { el.hidden = el.getAttribute('data-only') !== (pure ? 'pickup_deliver' : 'from_stock'); });
            }
            radios.forEach(function (r) { r.addEventListener('change', toggle); });
            toggle();
        })();
    </script>
@endsection
