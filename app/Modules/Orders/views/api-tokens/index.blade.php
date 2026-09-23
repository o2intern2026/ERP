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
            @foreach ($clients as $client)<option value="{{ $client->id }}" @selected((int) old('client_id') === $client->id)>{{ $client->name }}</option>@endforeach
        </select>
        <input name="name" value="{{ old('name') }}" placeholder="{{ __('orders.api.token_name') }}" maxlength="100" required>
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

{"order_type":"from_stock","external_ref":"PO-1001","deliver_to_name":"…","deliver_to_address":"…","deliver_to_suburb":"…","deliver_to_state":"VIC","deliver_to_postcode":"3000","requested_date":"2026-10-01","lines":[{"description_en":"…","package_type":"carton","carton_qty":10,"storage_tier":"standard","asn_line_id":null}]}</code></pre>
    <p class="text-muted"><small>{{ __('orders.api.storage_tier_hint') }}</small></p>

    {{-- CHANGE_REQUESTS #145 自动导入: a whole list in one call, the same file the portal upload takes. --}}
    <h2>{{ __('orders.api.import_usage_title') }}</h2>
    <p class="text-muted"><small>{{ __('orders.api.import_hint') }}</small></p>
    <pre><code>POST {{ $importEndpoint }}
Authorization: Bearer &lt;token&gt;
Content-Type: multipart/form-data

manifest=@list.xlsx                      {{ __('orders.api.import_fields.manifest') }}
order_type=from_stock                    {{ __('orders.api.import_fields.order_type') }}
group_by=recipient                       {{ __('orders.api.import_fields.group_by') }}
address_type_default=residential         {{ __('orders.api.import_fields.address_type_default') }}
auto_confirm=1                           {{ __('orders.api.import_fields.auto_confirm') }}
container_no= container_size= expected_date= reference= notes=
                                         {{ __('orders.api.import_fields.inbound') }}
requested_date= pickup[name]= pickup[phone]= pickup[address]= pickup[suburb]= pickup[state]= pickup[postcode]=
                                         {{ __('orders.api.import_fields.pickup') }}
force=1                                  {{ __('orders.api.import_fields.force') }}

201 {{ __('orders.api.import_responses.201') }}
202 {{ __('orders.api.import_responses.202') }}
200 {{ __('orders.api.import_responses.200') }}
422 {{ __('orders.api.import_responses.422') }}

GET {{ $importEndpoint }}/{import_id}    {{ __('orders.api.import_fields.show') }}</code></pre>
@endsection
