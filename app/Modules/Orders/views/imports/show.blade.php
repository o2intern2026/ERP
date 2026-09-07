@extends('layouts.app')

@section('title', __('orders.imports.show_title', ['id' => $import->id]))

@section('content')
    @php($audit = $import->errors ?? [])
    <h1>{{ __('orders.imports.show_title', ['id' => $import->id]) }}</h1>
    <p>{{ __('orders.fields.client') }}: <strong>{{ $import->client->name }}</strong> · {{ __('orders.imports.fields.status') }}: <strong>{{ __('orders.imports.statuses.'.$import->status) }}</strong></p>

    @if (session('status'))<article>{{ session('status') }}</article>@endif
    @if (($audit['warnings'] ?? []) !== [])
        <article><strong>{{ __('orders.imports.warning_title') }}</strong><ul>@foreach ($audit['warnings'] as $warning)<li>{{ $warning['message'] }}</li>@endforeach</ul></article>
    @endif
    @if (($audit['issues'] ?? []) !== [] || $import->error_count > 0)
        <p><a href="{{ route('orders.imports.errors', $import) }}">{{ __('orders.imports.actions.download_errors') }}</a></p>
    @endif
    @if (($audit['issues'] ?? []) !== [])
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('orders.imports.csv.row') }}</th><th>{{ __('orders.imports.csv.column') }}</th><th>{{ __('orders.imports.csv.message') }}</th></tr></thead>
            <tbody>@foreach ($audit['issues'] as $issue)<tr><td>{{ $issue['row'] }}</td><td>{{ $issue['column'] }}</td><td>{{ $issue['message'] }}</td></tr>@endforeach</tbody>
        </table></div>
    @endif

    @if ($import->status === 'pending')
        <form method="post" action="{{ route('orders.imports.confirm', $import) }}">
            @csrf
            <div class="overflow-auto"><table class="dense">
                <thead><tr><th>{{ __('orders.imports.fields.select') }}</th><th>{{ __('orders.fields.consignment_mark') }}</th><th>{{ __('orders.fields.destination') }}</th><th>{{ __('orders.fields.fba_reference') }}</th><th>{{ __('orders.imports.fields.rows') }}</th><th>{{ __('orders.imports.fields.status') }}</th><th>{{ __('orders.imports.fields.address_book') }}</th></tr></thead>
                <tbody>@foreach (($audit['groups'] ?? []) as $group)<tr>
                    <td>@if ($group['status'] === 'ready')<input type="checkbox" name="groups[]" value="{{ $group['key'] }}" checked>@else—@endif</td>
                    <td>{{ $group['consignment_mark'] }}</td><td>{{ $group['deliver_to_name'] }}<br><small>{{ $group['deliver_to_address'] }}, {{ $group['deliver_to_state'] }} {{ $group['deliver_to_postcode'] }}</small></td>
                    <td>{{ $group['fba_reference'] ?: __('orders.not_provided') }}</td><td>{{ implode(', ', $group['row_numbers']) }}</td>
                    <td>{{ __('orders.imports.group_statuses.'.$group['status']) }}@if ($group['message'])<br><small>{{ $group['message'] }}</small>@endif</td>
                    <td>@if ($group['status'] === 'ready' && $group['save_address_suggested'])<label><input type="checkbox" name="save_addresses[]" value="{{ $group['key'] }}"> {{ __('orders.imports.actions.save_address') }}</label>@elseif ($group['client_address_id']){{ __('orders.imports.address_matched') }}@if ($group['delivery_instructions'])<br><small>{{ $group['delivery_instructions'] }}</small>@endif @else—@endif</td>
                </tr>@endforeach</tbody>
            </table></div>
            <button type="submit">{{ __('orders.imports.actions.confirm') }}</button>
        </form>
    @else
        <p>{{ __('orders.imports.messages.completed', ['success' => count($audit['result']['created'] ?? []), 'failed' => $audit['result']['failed_rows'] ?? 0]) }}</p>
        @if (($audit['result']['created'] ?? []) !== [])<ul>@foreach ($audit['result']['created'] as $created)<li><a href="{{ route('orders.show', $created['order_id']) }}">{{ $created['order_no'] }}</a></li>@endforeach</ul>@endif
    @endif

    <a class="secondary" role="button" href="{{ route('orders.imports.index') }}">{{ __('orders.imports.actions.back') }}</a>
@endsection
