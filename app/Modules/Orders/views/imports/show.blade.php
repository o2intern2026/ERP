@extends('layouts.app')

@section('title', __('orders.imports.show_title', ['id' => $import->id]))

@section('content')
    @php($audit = $import->errors ?? [])
    @php($portal = $import->source === 'portal')
    @php($inbound = is_array($audit['context']['inbound'] ?? null) ? $audit['context']['inbound'] : [])
    <h1>{{ __('orders.imports.show_title', ['id' => $import->id]) }}</h1>
    <p>
        {{ __('orders.fields.client') }}: <strong>{{ $import->client->name }}</strong>
        · {{ __('orders.imports.fields.source') }}: <span class="badge" data-tone="{{ $portal ? 'info' : 'muted' }}">{{ __('orders.sources.'.$import->source) }}</span>
        · {{ __('orders.imports.fields.file_name') }}: {{ $audit['context']['original_name'] ?? '—' }}
        · {{ __('orders.imports.fields.status') }}: <strong>{!! \App\Support\Ui\StatusBadge::render('orders.imports.statuses.', $import->status) !!}</strong>
    </p>

    @if (session('status'))<article>{{ session('status') }}</article>@endif
    @if ($portal)
        {{-- CHANGE_REQUESTS #123: the client's own upload — its inbound context for 待建预报; the client confirms it in the portal, staff only read it here. --}}
        <article class="kv-card">
            <strong>{{ __('orders.imports.inbound.title') }}</strong>
            <dl class="kv-2">
                <dt>{{ __('orders.imports.inbound.container_no') }}</dt><dd>{{ ($inbound['container_no'] ?? null) ?: '—' }}</dd>
                <dt>{{ __('orders.imports.inbound.container_size') }}</dt><dd>@if (! empty($inbound['container_size'])){{ __('warehouse.container_sizes.'.$inbound['container_size']) }}@else — @endif</dd>
                <dt>{{ __('orders.imports.inbound.expected_date') }}</dt><dd>{{ ($inbound['expected_date'] ?? null) ?: '—' }}</dd>
                <dt>{{ __('orders.imports.inbound.reference') }}</dt><dd>{{ ($inbound['reference'] ?? null) ?: '—' }}</dd>
                <dt>{{ __('orders.imports.inbound.notes') }}</dt><dd>{{ ($inbound['notes'] ?? null) ?: '—' }}</dd>
                <dt>{{ __('orders.imports.inbound.uploaded_at') }}</dt><dd>{{ ($inbound['uploaded_at'] ?? null) ?: $import->created_at }}</dd>
            </dl>
            <p class="text-muted"><small>{{ __('orders.imports.portal_note') }}</small></p>
        </article>
    @endif
    @if (($audit['warnings'] ?? []) !== [])
        <article><strong>{{ __('orders.imports.warning_title') }}</strong><ul>@foreach ($audit['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul></article>
    @endif
    @if (($audit['issues'] ?? []) !== [] || $import->error_count > 0)
        <p><a href="{{ route('orders.imports.errors', $import) }}">{{ __('orders.imports.actions.download_errors') }}</a></p>
    @endif
    @if (($audit['issues'] ?? []) !== [])
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('orders.imports.csv.row') }}</th><th>{{ __('orders.imports.csv.column') }}</th><th>{{ __('orders.imports.csv.message') }}</th></tr></thead>
            <tbody>@foreach ($audit['issues'] as $issue)<tr><td>{{ $issue['row'] }}</td><td>{{ $issue['label'] ?? $issue['column'] }}</td><td>{{ $issue['message'] }}</td></tr>@endforeach</tbody>
        </table></div>
    @endif

    @if ($import->status === 'pending')
        {{-- A portal submission is confirmed by the client in the portal: the same table, read-only (no checkboxes, no button). --}}
        <form method="post" action="{{ route('orders.imports.confirm', $import) }}">
            @csrf
            <div class="overflow-auto"><table class="dense">
                <thead><tr>@unless ($portal)<th>{{ __('orders.imports.fields.select') }}</th>@endunless<th>{{ __('orders.fields.consignment_mark') }}</th><th>{{ __('orders.fields.destination') }}</th><th>{{ __('orders.fields.fba_reference') }}</th><th>{{ __('orders.imports.fields.rows') }}</th><th>{{ __('orders.imports.fields.storage_tier') }}</th><th>{{ __('orders.imports.fields.status') }}</th><th>{{ __('orders.imports.fields.address_book') }}</th></tr></thead>
                <tbody>@foreach (($audit['groups'] ?? []) as $group)<tr>
                    @unless ($portal)<td>@if ($group['status'] === 'ready')<input type="checkbox" name="groups[]" value="{{ $group['key'] }}" checked>@else—@endif</td>@endunless
                    <td>{{ $group['consignment_mark'] }}</td><td>{{ $group['deliver_to_name'] }}<br><small>{{ $group['deliver_to_address'] }}, {{ $group['deliver_to_state'] }} {{ $group['deliver_to_postcode'] }}</small></td>
                    <td>{{ $group['fba_reference'] ?: __('orders.not_provided') }}</td><td>{{ implode(', ', $group['row_numbers']) }}</td>
                    @php($bottomRows = collect($group['rows'] ?? [])->where('storage_tier', 'bottom'))
                    <td>@if ($bottomRows->isNotEmpty())<span class="badge" data-tone="warn">{{ __('orders.imports.tier_bottom_rows', ['count' => $bottomRows->count()]) }}</span>@if ($bottomRows->contains('storage_tier_source', 'value_rule'))<br><small>{{ __('orders.imports.tier_prefilled') }}</small>@endif @else{{ __('orders.imports.tier_standard') }}@endif</td>
                    <td>{!! \App\Support\Ui\StatusBadge::render('orders.imports.group_statuses.', $group['status']) !!}@if ($group['message'])<br><small>{{ $group['message'] }}</small>@endif</td>
                    <td>@if (! $portal && $group['status'] === 'ready' && $group['save_address_suggested'])<label><input type="checkbox" name="save_addresses[]" value="{{ $group['key'] }}"> {{ __('orders.imports.actions.save_address') }}</label>@elseif ($group['client_address_id']){{ __('orders.imports.address_matched') }}@if ($group['delivery_instructions'])<br><small>{{ $group['delivery_instructions'] }}</small>@endif @else—@endif</td>
                </tr>@endforeach</tbody>
            </table></div>
            @unless ($portal)<button type="submit">{{ __('orders.imports.actions.confirm') }}</button>@endunless
        </form>
    @else
        <p>{{ __('orders.imports.messages.completed', ['success' => count($audit['result']['created'] ?? []), 'failed' => $audit['result']['failed_rows'] ?? 0]) }}</p>
        @if (($audit['result']['created'] ?? []) !== [])<ul>@foreach ($audit['result']['created'] as $created)<li><a href="{{ route('orders.show', $created['order_id']) }}">{{ $created['order_no'] }}</a></li>@endforeach</ul>@endif
    @endif

    <a class="secondary" role="button" href="{{ route('orders.imports.index') }}">{{ __('orders.imports.actions.back') }}</a>
@endsection
