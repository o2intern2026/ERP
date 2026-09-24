@extends('layouts.app')

@section('title', __('orders.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('orders.title') }}</h1>
        @if (auth()->user()->hasAnyRole(['admin', 'customer_service', 'dispatcher']))
            <p style="text-align:right">
                <a class="secondary" role="button" href="{{ route('orders.addresses.index') }}">{{ __('orders.actions.address_book') }}</a>
                <a class="secondary" role="button" href="{{ route('orders.imports.index') }}">{{ __('orders.actions.import') }}</a>
                <a class="secondary" role="button" href="{{ route('orders.drafts.create') }}">{{ __('orders.drafts.nav') }}</a>
                <a role="button" href="{{ route('orders.create') }}">{{ __('orders.actions.create') }}</a>
            </p>
        @endif
    </header>

    <form method="get">
        <div class="grid">
            <select name="client_id" aria-label="{{ __('orders.fields.client') }}">
                <option value="">{{ __('orders.filters.all_clients') }}</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected((int) ($filters['client_id'] ?? 0) === $client->id)>{{ $client->name }}</option>
                @endforeach
            </select>
            <select name="status" aria-label="{{ __('orders.fields.operational_status') }}">
                <option value="">{{ __('orders.filters.all_statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('orders.statuses.operational.'.$status) }}</option>
                @endforeach
            </select>
            <select name="state" aria-label="{{ __('orders.fields.state') }}">
                <option value="">{{ __('orders.filters.all_states') }}</option>
                @foreach ($states as $state)
                    <option value="{{ $state }}" @selected(($filters['state'] ?? '') === $state)>{{ $state }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid">
            <input name="consignment_mark" value="{{ $filters['consignment_mark'] ?? '' }}" placeholder="{{ __('orders.fields.consignment_mark') }}">
            <x-date-field name="requested_date" value="{{ $filters['requested_date'] ?? '' }}" aria-label="{{ __('orders.fields.requested_date') }}" />
            <button type="submit" class="secondary">{{ __('orders.actions.filter') }}</button>
        </div>
    </form>

    @if ($orders->isEmpty())
        <p>{{ __('orders.empty') }}</p>
    @else
        {{-- CHANGE_REQUESTS #153 一键确认: the 已接收 rows carry a checkbox bound to this form (form="confirm-bulk"); one post confirms them
             like the single 确认订单并检查库存 button, refused ones named. --}}
        @php($confirmable = $orders->getCollection()->where('operational_status', 'received'))
        @role('admin|customer_service|dispatcher')
            @if ($confirmable->isNotEmpty())
                <form method="post" action="{{ route('orders.confirm_bulk') }}" id="confirm-bulk">
                    @csrf
                    <article class="kv-card">
                        <strong>{{ __('orders.bulk_confirm.title') }}</strong>
                        <p class="text-muted"><small>{{ __('orders.bulk_confirm.hint') }}</small></p>
                        <p style="margin:0">
                            <button type="button" class="secondary outline" id="confirm-select-all" style="padding:.15rem .6rem">{{ __('orders.bulk_confirm.select_all', ['count' => $confirmable->count()]) }}</button>
                            <button type="button" class="secondary outline" id="confirm-select-none" style="padding:.15rem .6rem">{{ __('orders.bulk_confirm.select_none') }}</button>
                            <button type="submit" id="confirm-bulk-submit" data-label="{{ __('orders.bulk_confirm.submit') }}" disabled>{{ __('orders.bulk_confirm.submit', ['count' => 0]) }}</button>
                        </p>
                    </article>
                </form>
            @endif
        @endrole
        <div class="overflow-auto">
            <table class="dense">
                <thead>
                    <tr>
                        @role('admin|customer_service|dispatcher')<th>@if ($confirmable->isNotEmpty())<input type="checkbox" id="confirm-select-page" aria-label="{{ __('orders.bulk_confirm.select_all', ['count' => $confirmable->count()]) }}">@endif</th>@endrole
                        <th>{{ __('orders.fields.order_no') }}</th>
                        <th>{{ __('orders.fields.client') }}</th>
                        <th>{{ __('orders.fields.job') }}</th>
                        <th>{{ __('orders.fields.consignment_mark') }}</th>
                        <th>{{ __('orders.fields.destination') }}</th>
                        <th>{{ __('orders.fields.requested_date') }}</th>
                        <th>{{ __('orders.fields.operational_status') }}</th>
                        <th>{{ __('orders.fields.billing_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            @role('admin|customer_service|dispatcher')<td>@if ($order->operational_status === 'received')<input type="checkbox" name="order_ids[]" value="{{ $order->id }}" form="confirm-bulk" class="confirm-row" aria-label="{{ $order->order_no }}">@endif</td>@endrole
                            <td><a href="{{ route('orders.show', $order) }}">{{ $order->order_no }}</a></td>
                            <td>{{ $order->client->name }}</td>
                            <td>{{ $order->job->job_no }}</td>
                            <td>{{ $order->consignment_mark ?: __('orders.not_provided') }}</td>
                            <td>{{ $order->deliver_to_suburb }}, {{ $order->deliver_to_state }}</td>
                            <td>{{ $order->requested_date->format('Y-m-d') }}</td>
                            <td>{!! \App\Support\Ui\StatusBadge::render('orders.statuses.operational.', $order->operational_status) !!}
                                @if (in_array('financial', $holdTypes[$order->id] ?? [], true))<span class="badge" data-tone="danger">{{ __('orders.holds.financial_badge') }}</span>@endif
                            </td>
                            <td>{!! \App\Support\Ui\StatusBadge::render('orders.statuses.billing.', $order->billing_status) !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        // CHANGE_REQUESTS #153: 全选 / 取消 / header tick and the live count on the 一键确认 button; nothing ticked → no submit.
        const form = document.getElementById('confirm-bulk');
        if (!form) return;
        const rows = () => Array.from(document.querySelectorAll('input.confirm-row'));
        const submit = document.getElementById('confirm-bulk-submit'), page = document.getElementById('confirm-select-page');
        const sync = () => {
            const n = rows().filter(b => b.checked).length;
            submit.textContent = submit.dataset.label.replace(':count', String(n)); submit.disabled = n === 0;
            if (page) { page.checked = n > 0 && n === rows().length; page.indeterminate = n > 0 && n < rows().length; }
        };
        const setAll = (on) => { rows().forEach(b => { b.checked = on; }); sync(); };
        document.getElementById('confirm-select-all')?.addEventListener('click', () => setAll(true));
        document.getElementById('confirm-select-none')?.addEventListener('click', () => setAll(false));
        page?.addEventListener('change', () => setAll(page.checked));
        rows().forEach(b => b.addEventListener('change', sync));
        form.addEventListener('submit', event => { if (rows().filter(b => b.checked).length === 0) event.preventDefault(); });
        sync();
    })();
</script>
@endpush
