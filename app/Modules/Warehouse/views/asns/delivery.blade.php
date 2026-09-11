@extends('layouts.app')

@section('title', __('warehouse.asns.delivery_title', ['asn' => $asn->asn_no, 'id' => $line->id]))

@section('content')
    <p><a href="{{ route('warehouse.asns.show', $asn) }}#lines">← {{ $asn->asn_no }}</a></p>
    <header>
        <h1>{{ __('warehouse.asns.edit_delivery') }}</h1>
        <p>{{ $asn->asn_no }} · {{ $asn->client->name }} · #{{ $line->id }} · {{ $line->consignment_mark ?: '—' }} · {{ $line->description }} · {{ $line->expected_cartons }} {{ __('warehouse.asns.expected') }}</p>
    </header>
    <p class="text-muted"><small>{{ __('warehouse.asns.delivery_hint') }}</small></p>

    @if ($line->isOnOrder())
        <p><mark>{{ __('warehouse.asns.errors.delivery_locked', ['id' => $line->id]) }}</mark></p>
    @else
        <form method="post" action="{{ route('warehouse.asns.lines.delivery.update', [$asn, $line]) }}">
            @csrf
            <div class="grid">
                <label>{{ __('warehouse.asns.delivery_fields.consignment_mark') }}<input type="text" name="consignment_mark" maxlength="60" value="{{ old('consignment_mark', $line->consignment_mark) }}"></label>
                <label>{{ __('warehouse.asns.delivery_fields.fba_reference') }}<input type="text" name="fba_reference" maxlength="60" value="{{ old('fba_reference', $line->fba_reference) }}"></label>
            </div>
            <div class="grid">
                <label>{{ __('warehouse.asns.delivery_fields.deliver_to_name') }}<input type="text" name="deliver_to_name" maxlength="255" required value="{{ old('deliver_to_name', $line->deliver_to_name) }}"></label>
                <label>{{ __('warehouse.asns.delivery_fields.deliver_to_phone') }}<input type="text" name="deliver_to_phone" maxlength="40" value="{{ old('deliver_to_phone', $line->deliver_to_phone) }}"></label>
            </div>
            <label>{{ __('warehouse.asns.delivery_fields.deliver_to_address') }}<input type="text" name="deliver_to_address" maxlength="255" required value="{{ old('deliver_to_address', $line->deliver_to_address) }}"></label>
            <div class="grid">
                <label>{{ __('warehouse.asns.delivery_fields.deliver_to_suburb') }}<input type="text" name="deliver_to_suburb" maxlength="100" required value="{{ old('deliver_to_suburb', $line->deliver_to_suburb) }}"></label>
                <label>{{ __('warehouse.asns.delivery_fields.deliver_to_state') }}<select name="deliver_to_state" required><option value="">—</option>@foreach (\App\Support\Enums::STATES as $state)<option value="{{ $state }}" @selected(old('deliver_to_state', $line->deliver_to_state) === $state)>{{ $state }}</option>@endforeach</select></label>
                <label>{{ __('warehouse.asns.delivery_fields.deliver_to_postcode') }}<input type="text" name="deliver_to_postcode" maxlength="10" inputmode="numeric" required value="{{ old('deliver_to_postcode', $line->deliver_to_postcode) }}"></label>
            </div>
            @if ($siblings > 0)
                <label><input type="checkbox" name="apply_to_mark" value="1" @checked(old('apply_to_mark', '1'))> {{ __('warehouse.asns.delivery_apply_to_mark', ['count' => $siblings, 'mark' => $line->consignment_mark]) }}</label>
            @endif
            <button type="submit">{{ __('platform.common.save') }}</button>
        </form>
    @endif
@endsection
