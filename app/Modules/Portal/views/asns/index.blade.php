@extends('layouts.app')

@section('title', __('portal.asns.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('portal.asns.title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('portal.asns.create') }}">{{ __('portal.asns.create') }}</a></p>
    </header>
    <p class="text-muted"><small>{{ __('portal.asns.hint') }}</small></p>

    <form method="get">
        <div class="grid">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('portal.asns.search') }}" aria-label="{{ __('portal.asns.search') }}">
            <select name="status" aria-label="{{ __('portal.fields.status') }}">
                <option value="">{{ __('portal.filters.all_statuses') }}</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __('warehouse.asn_statuses.'.$status) }}</option>
                @endforeach
            </select>
            <button type="submit" class="secondary">{{ __('portal.actions.filter') }}</button>
        </div>
    </form>

    @if ($asns->isEmpty())
        <p>{{ __('portal.asns.empty') }}</p>
    @else
        <div class="overflow-auto">
            <table class="dense">
                <thead><tr>
                    <th>{{ __('portal.asns.fields.asn_no') }}</th>
                    <th>{{ __('portal.asns.fields.reference') }}</th>
                    <th>{{ __('portal.asns.fields.containers') }}</th>
                    <th>{{ __('portal.asns.fields.warehouse') }}</th>
                    <th>{{ __('portal.asns.fields.inbound_type') }}</th>
                    <th>{{ __('portal.asns.fields.expected_date') }}</th>
                    <th class="num">{{ __('portal.asns.fields.lines') }}</th>
                    <th class="num">{{ __('portal.asns.fields.expected_cartons') }}</th>
                    <th class="num">{{ __('portal.asns.fields.received_cartons') }}</th>
                    <th>{{ __('portal.fields.status') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ($asns as $asn)
                        <tr>
                            <td><a href="{{ route('portal.asns.show', $asn) }}">{{ $asn->asn_no }}</a></td>
                            <td>{{ $asn->job?->reference ?? '—' }}</td>
                            <td>{{ $asn->containers->pluck('container_no')->implode(', ') ?: '—' }}</td>
                            <td>{{ $asn->warehouse->code }}</td>
                            <td>{{ __('warehouse.inbound_types.'.$asn->inbound_type) }}</td>
                            <td>{{ $asn->expected_date?->format('Y-m-d') ?? '—' }}</td>
                            <td class="num">{{ $asn->lines_count }}</td>
                            <td class="num">{{ (int) $asn->lines_sum_expected_cartons }}</td>
                            <td class="num">{{ (int) $asn->lines_sum_received_cartons }}</td>
                            <td>
                                {!! \App\Support\Ui\StatusBadge::render('warehouse.asn_statuses.', $asn->status) !!}
                                @if ($asn->isPendingClientConfirmation())<span class="badge" data-tone="warn">{{ __('portal.asns.pending_badge') }}</span>@elseif ($asn->isClientSubmitted())<span class="badge" data-tone="ok">{{ __('portal.asns.confirmed_badge') }}</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $asns->links() }}
    @endif
@endsection
