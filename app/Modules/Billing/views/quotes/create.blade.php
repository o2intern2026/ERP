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
                    {{-- Audit 2026-09-10: a spare row the user touched (or that carries an error) stays open after a validation round trip --}}
                    @php($collapsed = $i > 2 && old("lines.$i.charge_code") === null && old("lines.$i.qty") === null && ! $errors->has("lines.$i.qty") && ! $errors->has("lines.$i.charge_code"))
                    <div class="grid quote-line"{{ $collapsed ? ' hidden' : '' }}>
                        <select name="lines[{{ $i }}][charge_code]" @error("lines.$i.charge_code") aria-invalid="true" @enderror><option value="">{{ __('billing.quotes.line_code') }}</option>@foreach ($codes as $code)<option value="{{ $code->code }}" @selected(old("lines.$i.charge_code") === $code->code)>{{ $code->code }} · {{ $code->customer_description }}</option>@endforeach</select>
                        <input type="number" step="0.001" min="0.001" name="lines[{{ $i }}][qty]" placeholder="{{ __('billing.quotes.line_qty') }}" value="{{ old("lines.$i.qty") }}" @if (old("lines.$i.charge_code") !== null) required @endif @error("lines.$i.qty") aria-invalid="true" @enderror>
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
<script>
document.getElementById('add-line').addEventListener('click', () => { const r = document.querySelector('#lines .quote-line[hidden]'); if (r) r.hidden = false; });
// A row with a charge code needs a qty (server rule: exclude_without / required / gt:0) — mirror it in the browser so the field is marked before submit.
document.querySelectorAll('#lines .quote-line').forEach((row) => {
    const code = row.querySelector('select[name$="[charge_code]"]'), qty = row.querySelector('input[name$="[qty]"]');
    const sync = () => { qty.required = code.value !== ''; };
    code.addEventListener('change', sync); sync();
});
</script>
@endpush
