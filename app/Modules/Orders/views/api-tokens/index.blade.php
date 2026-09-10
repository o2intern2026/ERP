@extends('layouts.app')

@section('title', __('orders.api.title'))

@section('content')
    <p><a href="{{ route('orders.index') }}">← {{ __('orders.actions.back') }}</a></p>
    <h1>{{ __('orders.api.title') }}</h1>
    <p class="text-muted"><small>{{ __('orders.api.hint', ['endpoint' => $endpoint]) }}</small></p>

    @if (session('plain_token'))
        <article class="flash"><strong>{{ __('orders.api.show_once') }}</strong><br><code>{{ session('plain_token') }}</code></article>
    @endif

    <form method="post" action="{{ route('orders.api-tokens.store') }}" class="grid">
        @csrf
        <select name="client_id" required aria-label="{{ __('orders.fields.client') }}">
            <option value="">{{ __('orders.actions.select') }}</option>
            @foreach ($clients as $client)<option value="{{ $client->id }}">{{ $client->name }}</option>@endforeach
        </select>
        <input name="name" placeholder="{{ __('orders.api.token_name') }}" required>
        <button type="submit">{{ __('orders.api.issue') }}</button>
    </form>

    <table class="dense">
        <thead><tr><th>{{ __('orders.fields.client') }}</th><th>{{ __('orders.api.token_name') }}</th><th>{{ __('orders.api.last_used') }}</th><th>{{ __('orders.api.status') }}</th><th></th></tr></thead>
        <tbody>
            @forelse ($tokens as $token)
                <tr>
                    <td>{{ $token->client->name }}</td>
                    <td>{{ $token->name }} <small class="text-muted">{{ $token->creator?->name }}</small></td>
                    <td>{{ $token->last_used_at?->format('Y-m-d H:i') ?? __('orders.api.never_used') }}</td>
                    <td><span class="badge" data-tone="{{ $token->isActive() ? 'ok' : 'muted' }}">{{ __($token->isActive() ? 'orders.api.active' : 'orders.api.revoked') }}</span></td>
                    <td>
                        @if ($token->isActive())
                            <form method="post" action="{{ route('orders.api-tokens.revoke', $token) }}" class="inline">@csrf<button type="submit" class="secondary outline">{{ __('orders.api.revoke') }}</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted">{{ __('orders.api.none') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('orders.api.usage_title') }}</h2>
    <pre><code>POST {{ $endpoint }}
Authorization: Bearer &lt;token&gt;
Idempotency-Key: &lt;{{ __('orders.api.idempotency_hint') }}&gt;
Content-Type: application/json

{"order_type":"from_stock","external_ref":"PO-1001","deliver_to_name":"…","deliver_to_address":"…","deliver_to_suburb":"…","deliver_to_state":"VIC","deliver_to_postcode":"3000","requested_date":"2026-10-01","lines":[{"description_en":"…","package_type":"carton","carton_qty":10,"asn_line_id":null}]}</code></pre>
@endsection
