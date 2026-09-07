@extends('layouts.app')

@section('title', __('billing.quotes.create'))

@section('content')
    <h1>{{ __('billing.quotes.create') }}</h1>
    <form method="post" action="{{ route('billing.quotes.store') }}">
        @csrf
        <div class="grid">
            <label>{{ __('billing.quotes.client') }}<select name="client_id" required><option value="">—</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></label>
            <label>{{ __('billing.quotes.stage') }}<select name="stage">@foreach (\App\Support\Enums::QUOTE_STAGES as $s)<option value="{{ $s }}" @selected(old('stage', 'preliminary') === $s)>{{ __('billing.quotes.stages.'.$s) }}</option>@endforeach</select></label>
            <label>{{ __('billing.quotes.valid_until') }}<input type="date" name="valid_until" value="{{ old('valid_until', today()->addDays(14)->toDateString()) }}"></label>
        </div>
        <fieldset>
            <legend>{{ __('billing.quotes.lines') }}</legend>
            <div id="lines">
                @for ($i = 0; $i < 6; $i++)
                    <div class="grid quote-line" @if ($i > 2) hidden @endif>
                        <select name="lines[{{ $i }}][charge_code]"><option value="">{{ __('billing.quotes.line_code') }}</option>@foreach ($codes as $code)<option value="{{ $code->code }}" @selected(old("lines.$i.charge_code") === $code->code)>{{ $code->code }} · {{ $code->customer_description }}</option>@endforeach</select>
                        <input type="number" step="0.001" min="0" name="lines[{{ $i }}][qty]" placeholder="{{ __('billing.quotes.line_qty') }}" value="{{ old("lines.$i.qty") }}">
                        <input type="number" step="0.01" min="0" name="lines[{{ $i }}][weight_kg]" placeholder="{{ __('billing.quotes.line_weight') }}" value="{{ old("lines.$i.weight_kg") }}">
                        <input type="number" step="0.01" min="0" name="lines[{{ $i }}][cost]" placeholder="{{ __('billing.quotes.line_cost') }}" value="{{ old("lines.$i.cost") }}">
                    </div>
                @endfor
            </div>
            <button type="button" class="secondary outline" id="add-line">{{ __('billing.quotes.add_line') }}</button>
        </fieldset>
        <label>{{ __('billing.quotes.notes') }}<input type="text" name="notes" value="{{ old('notes') }}"></label>
        <button type="submit">{{ __('billing.quotes.create') }}</button>
    </form>
@endsection

@push('scripts')
<script>document.getElementById('add-line').addEventListener('click', () => { const r = document.querySelector('#lines .quote-line[hidden]'); if (r) r.hidden = false; });</script>
@endpush
