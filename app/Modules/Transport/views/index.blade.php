@extends('layouts.app')

@section('title', __('transport.title'))

@section('content')
    <h1>{{ __('transport.title') }}</h1>

    {{-- CHANGE_REQUESTS #160 / #161: status + page-size filter so 全选本页 can cover a whole batch. --}}
    <form method="get" action="{{ route('transport.index') }}" class="grid" style="align-items:end">
        <label>{{ __('transport.filters.status') }}
            <select name="status">
                <option value="">{{ __('transport.filters.all_statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('transport.statuses.'.$status) }}</option>
                @endforeach
            </select>
        </label>
        <label>{{ __('transport.filters.per_page') }}
            <select name="per_page">
                @foreach ([25, 100, 300] as $size)
                    <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 25) === $size)>{{ __('transport.filters.per_page_option', ['count' => $size]) }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="secondary">{{ __('transport.filters.apply') }}</button>
    </form>

    @if ($shipments->isEmpty())
        <p>{{ __('transport.shipments.empty') }}</p>
    @else
        {{-- CHANGE_REQUESTS #160 批量确认最终方案: 已报价 rows whose final quote can be picked without a person (client's plan or recommended)
             carry a checkbox bound to this form (form="confirm-bulk"); one post confirms them like the single 确认最终方案 button. --}}
        @if ($confirmable->isNotEmpty())
            <form method="post" action="{{ route('transport.quotes.confirm_bulk') }}" id="confirm-bulk" data-rows="confirm-row">
                @csrf
                <article class="kv-card">
                    <strong>{{ __('transport.bulk_confirm.title') }}</strong>
                    <p class="text-muted"><small>{{ __('transport.bulk_confirm.hint') }}</small></p>
                    <p style="margin:0">
                        <button type="button" class="secondary outline" data-select="all" style="padding:.15rem .6rem">{{ __('transport.bulk_confirm.select_all', ['count' => $confirmable->count()]) }}</button>
                        <button type="button" class="secondary outline" data-select="none" style="padding:.15rem .6rem">{{ __('transport.bulk_confirm.select_none') }}</button>
                        <button type="submit" data-label="{{ __('transport.bulk_confirm.submit') }}" disabled>{{ __('transport.bulk_confirm.submit', ['count' => 0]) }}</button>
                    </p>
                </article>
            </form>
        @endif
        {{-- CHANGE_REQUESTS #161 批量确认预订: 报价已确认 rows carry a checkbox bound to this form (form="book-bulk"). --}}
        @if ($bookable->isNotEmpty())
            <form method="post" action="{{ route('transport.book_bulk') }}" id="book-bulk" data-rows="book-row">
                @csrf
                <article class="kv-card">
                    <strong>{{ __('transport.bulk_book.title') }}</strong>
                    <p class="text-muted"><small>{{ __('transport.bulk_book.hint') }}</small></p>
                    <p style="margin:0">
                        <button type="button" class="secondary outline" data-select="all" style="padding:.15rem .6rem">{{ __('transport.bulk_book.select_all', ['count' => $bookable->count()]) }}</button>
                        <button type="button" class="secondary outline" data-select="none" style="padding:.15rem .6rem">{{ __('transport.bulk_book.select_none') }}</button>
                        <button type="submit" data-label="{{ __('transport.bulk_book.submit') }}" disabled>{{ __('transport.bulk_book.submit', ['count' => 0]) }}</button>
                    </p>
                </article>
            </form>
        @endif
        @php($ticks = $confirmable->isNotEmpty() || $bookable->isNotEmpty())
        <div class="overflow-auto">
        <table class="dense">
            <thead>
                <tr>
                    @if ($ticks)<th></th>@endif
                    <th>{{ __('transport.shipments.number') }}</th>
                    <th>{{ __('transport.shipments.job') }}</th>
                    <th>{{ __('transport.shipments.type') }}</th>
                    <th>{{ __('transport.shipments.status') }}</th>
                    <th>{{ __('transport.shipments.carrier') }}</th>
                    <th>{{ __('transport.shipments.service_level') }}</th>
                    <th>{{ __('transport.shipments.tracking_number') }}</th>
                    <th class="num">{{ __('transport.costs.revenue') }}</th>
                    <th class="num">{{ __('transport.costs.payable') }}</th>
                    <th class="num">{{ __('transport.costs.margin') }}</th>
                    <th class="num">{{ __('transport.shipments.quote_count') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($shipments as $shipment)
                    @php($plan = $confirmable[$shipment->id] ?? null)
                    <tr>
                        @if ($ticks)
                            <td>
                                @if ($plan !== null)
                                    <input type="checkbox" class="confirm-row" name="shipment_ids[]" value="{{ $shipment->id }}" form="confirm-bulk" aria-label="{{ $shipment->shipment_no }}">
                                @elseif ($bookable->has($shipment->id))
                                    <input type="checkbox" class="book-row" name="shipment_ids[]" value="{{ $shipment->id }}" form="book-bulk" aria-label="{{ $shipment->shipment_no }}">
                                @endif
                            </td>
                        @endif
                        <td><a href="{{ route('transport.shipments.show', $shipment) }}">{{ $shipment->shipment_no }}</a></td>
                        <td>{{ $shipment->job?->job_no ?? $shipment->job_id }}</td>
                        <td>{{ __('transport.shipment_types.'.$shipment->shipment_type) }}@if ($shipment->asn_id) <small class="text-muted">· <a href="{{ route('warehouse.asns.show', $shipment->asn_id) }}">{{ $asnNos[$shipment->asn_id] ?? $shipment->asn_id }}</a></small>@endif</td>
                        <td>{!! \App\Support\Ui\StatusBadge::render('transport.statuses.', $shipment->status) !!}</td>
                        <td>
                            {{ $shipment->carrier?->name ?? __('transport.not_selected') }}
                            @if ($plan !== null)
                                <br><small class="text-muted">{{ __('transport.bulk_confirm.will_confirm', ['plan' => ($plan['quote']->carrier?->name ?? __('transport.sources.'.$plan['quote']->source)).' · '.__('transport.service_levels.'.$plan['quote']->service_level).' · '.\App\Support\Money::cents((int) $plan['quote']->customer_price_cents)->format()]) }}
                                    <span class="badge" data-tone="{{ $plan['basis'] === 'client' ? 'ok' : 'warn' }}">{{ __('transport.bulk_confirm.'.$plan['basis']) }}</span></small>
                            @endif
                        </td>
                        <td>{{ $shipment->service_level ? __('transport.service_levels.'.$shipment->service_level) : __('transport.not_selected') }}</td>
                        <td>{{ $shipment->tracking_number ?: __('transport.not_selected') }}</td>
                        <td class="num">{{ \App\Support\Money::cents($margins[$shipment->id]['revenue_cents'])->format() }}</td>
                        <td class="num">{{ $margins[$shipment->id]['payable_cost_cents'] === null ? __('transport.costs.pending') : \App\Support\Money::cents($margins[$shipment->id]['payable_cost_cents'])->format() }}</td>
                        <td class="num">{{ $margins[$shipment->id]['margin_cents'] === null ? __('transport.costs.pending') : \App\Support\Money::cents($margins[$shipment->id]['margin_cents'])->format() }}</td>
                        <td class="num">{{ $shipment->quotes_count }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        {{ $shipments->links() }}
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        // CHANGE_REQUESTS #160 / #161: 全选 / 取消 and the live count on each bulk button; nothing ticked → no submit.
        document.querySelectorAll('form[data-rows]').forEach(form => {
            const rows = () => Array.from(document.querySelectorAll('input.' + form.dataset.rows));
            const submit = form.querySelector('button[type="submit"]');
            const sync = () => {
                const n = rows().filter(b => b.checked).length;
                submit.textContent = submit.dataset.label.replace(':count', String(n)); submit.disabled = n === 0;
            };
            const setAll = (on) => { rows().forEach(b => { b.checked = on; }); sync(); };
            form.querySelector('[data-select="all"]').addEventListener('click', () => setAll(true));
            form.querySelector('[data-select="none"]').addEventListener('click', () => setAll(false));
            rows().forEach(b => b.addEventListener('change', sync));
            form.addEventListener('submit', event => { if (rows().filter(b => b.checked).length === 0) event.preventDefault(); });
            sync();
        });
    })();
</script>
@endpush
