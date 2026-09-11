@extends('layouts.app')

@section('title', __('warehouse.asns.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('warehouse.asns.title') }}</h1>
        @role('admin|warehouse_supervisor|warehouse_operator|customer_service')
            <p style="text-align:right"><a role="button" href="{{ route('warehouse.asns.create') }}">{{ __('warehouse.asns.create') }}</a></p>
        @endrole
    </header>
    <form method="get" class="grid">
        <select name="status"><option value="">{{ __('warehouse.asns.status') }}: {{ __('platform.jobs.all') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('warehouse.asn_statuses.'.$s) }}</option>@endforeach</select>
        <select name="client_id"><option value="">{{ __('warehouse.asns.client') }}: {{ __('platform.jobs.all') }}</option>@foreach ($clients as $c)<option value="{{ $c->id }}" @selected((int) ($filters['client_id'] ?? 0) === $c->id)>{{ $c->name }}</option>@endforeach</select>
        <label style="align-self:center"><input type="checkbox" name="pending" value="1" @checked($filters['pending'] ?? false)> {{ __('warehouse.asns.filter_pending') }}</label>
        <button type="submit" class="secondary">{{ __('platform.common.filter') }}</button>
    </form>
    @if ($asns->isEmpty())
        <p class="text-muted">{{ __('warehouse.asns.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('warehouse.asns.asn_no') }}</th><th>{{ __('warehouse.asns.client') }}</th><th>{{ __('warehouse.asns.job') }}</th><th>{{ __('warehouse.asns.warehouse') }}</th><th>{{ __('warehouse.asns.inbound_type') }}</th><th>{{ __('warehouse.asns.expected_date') }}</th><th class="num">{{ __('warehouse.asns.containers') }}</th><th class="num">{{ __('warehouse.asns.lines') }}</th><th>{{ __('warehouse.asns.status') }}</th></tr></thead>
            <tbody>
            @foreach ($asns as $a)
                <tr>
                    <td><a href="{{ route('warehouse.asns.show', $a) }}">{{ $a->asn_no }}</a> @if ($a->unplanned)<span class="badge" data-tone="warn">{{ __('warehouse.asns.unplanned_badge') }}</span>@endif @if ($a->isPendingClientConfirmation())<span class="badge" data-tone="warn">{{ __('warehouse.asns.client_pending_badge') }}</span>@endif</td>
                    <td>{{ $a->client->name }}</td><td>{{ $a->job->job_no }}</td><td>{{ $a->warehouse->code }}</td>
                    <td>{{ __('warehouse.inbound_types.'.$a->inbound_type) }}</td><td>{{ $a->expected_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $a->containers_count }}</td><td class="num">{{ $a->lines_count }}</td>
                    <td><span class="badge" data-tone="{{ $a->status === 'putaway' || $a->status === 'closed' ? 'ok' : 'warn' }}">{{ __('warehouse.asn_statuses.'.$a->status) }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        {{ $asns->links() }}
    @endif
@endsection
