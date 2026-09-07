@extends('layouts.app')

@section('title', __('platform.webhooks.title'))

@section('content')
    <h1>{{ __('platform.webhooks.title') }}</h1>
    <p class="text-muted"><small>{{ __('platform.webhooks.hint') }}</small></p>
    <form method="post" action="{{ route('platform.webhooks.store') }}" class="grid">
        @csrf
        <input type="text" name="name" placeholder="{{ __('platform.webhooks.name') }}" maxlength="60" required>
        <input type="url" name="url" placeholder="{{ __('platform.webhooks.url') }}" required>
        <input type="text" name="events" placeholder="{{ __('platform.webhooks.events') }}" value="*" required>
        <button type="submit" class="secondary">{{ __('platform.webhooks.create') }}</button>
    </form>
    @if ($endpoints->isEmpty())
        <p class="text-muted">{{ __('platform.webhooks.empty') }}</p>
    @else
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('platform.webhooks.name') }}</th><th>{{ __('platform.webhooks.url') }}</th><th>{{ __('platform.webhooks.events') }}</th><th>{{ __('platform.webhooks.secret') }}</th><th>{{ __('platform.webhooks.active') }}</th><th class="num">{{ __('platform.webhooks.deliveries') }}</th><th>{{ __('platform.common.actions') }}</th></tr></thead>
            <tbody>
            @foreach ($endpoints as $e)
                <tr>
                    <td>{{ $e->name }}</td><td><code>{{ $e->url }}</code></td><td>{{ implode(', ', $e->events) }}</td><td><code>{{ $e->secret }}</code></td>
                    <td><span class="badge" data-tone="{{ $e->active ? 'ok' : 'muted' }}">{{ $e->active ? __('platform.common.yes') : __('platform.common.no') }}</span></td>
                    <td class="num">{{ $e->deliveries_count }}</td>
                    <td>
                        <form method="post" action="{{ route('platform.webhooks.toggle', $e) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('platform.webhooks.toggle') }}</button></form>
                        <form method="post" action="{{ route('platform.webhooks.destroy', $e) }}" class="inline">@csrf @method('DELETE')<button type="submit" class="secondary outline">{{ __('platform.webhooks.delete') }}</button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
    @if ($deliveries->isNotEmpty())
        <h2>{{ __('platform.webhooks.deliveries') }}</h2>
        <div class="overflow-auto"><table class="dense">
            <thead><tr><th>{{ __('platform.webhooks.name') }}</th><th>{{ __('platform.integration.event') }}</th><th>{{ __('platform.webhooks.status') }}</th><th class="num">{{ __('platform.webhooks.attempts') }}</th><th>{{ __('platform.webhooks.response') }}</th><th>{{ __('platform.integration.last_error') }}</th><th>{{ __('platform.activity.time') }}</th></tr></thead>
            <tbody>
            @foreach ($deliveries as $d)
                <tr><td>{{ $d->endpoint?->name }}</td><td><code>{{ $d->event_name }}</code><br><small class="text-muted">{{ $d->event_id }}</small></td><td><span class="badge" data-tone="{{ ['pending' => 'muted', 'delivered' => 'ok', 'failed' => 'danger'][$d->status] }}">{{ __('platform.webhooks.statuses.'.$d->status) }}</span></td><td class="num">{{ $d->attempts }}</td><td>{{ $d->response_code }}</td><td><small>{{ \Illuminate\Support\Str::limit($d->last_error, 80) }}</small></td><td>{{ ($d->delivered_at ?? $d->created_at)?->format('m-d H:i') }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
@endsection
