{{-- Date range (defaults to the current month) and, for the staff client view, the client picker. $action, $period, $clients (collection, may be empty), $client (nullable). --}}
<form method="get" action="{{ $action }}" class="grid">
    @if (isset($clients) && $clients->isNotEmpty())
        <label>{{ __('reports.filters.client') }}
            <select name="client_id" required>
                <option value="">{{ __('reports.filters.pick_client') }}</option>
                @foreach ($clients as $option)
                    <option value="{{ $option->id }}" @selected(($client?->id) === $option->id)>{{ $option->code }} — {{ $option->name }}</option>
                @endforeach
            </select>
        </label>
    @endif
    <label>{{ __('reports.filters.from') }}<input type="date" name="from" value="{{ $period->from->toDateString() }}" required></label>
    <label>{{ __('reports.filters.to') }}<input type="date" name="to" value="{{ $period->to->toDateString() }}" required></label>
    <label>&nbsp;<button type="submit" class="secondary">{{ __('reports.actions.apply') }}</button></label>
</form>
