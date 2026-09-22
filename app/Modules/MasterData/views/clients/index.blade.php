@extends('layouts.app')

@section('title', __('masterdata.clients.title'))

@section('content')
    <h1>{{ __('masterdata.title') }}</h1>
    @include('masterdata::partials.tabs', ['active' => 'clients'])
    @if ($pendingCount ?? 0)<p><mark>{{ __('masterdata.clients.pending_hint', ['count' => $pendingCount]) }}</mark></p>@endif
    {{-- CHANGE_REQUESTS #134: no staff "new client" form — companies register themselves, staff approve here. --}}
    @if ($signupOpen ?? true)
        <p class="text-muted"><small>{{ __('masterdata.clients.signup_hint') }} <a href="{{ route('platform.register') }}">{{ __('masterdata.clients.signup_link') }}</a></small></p>
    @else
        <p class="text-muted"><small>{{ __('masterdata.clients.signup_closed_hint') }}</small></p>
    @endif
    <div class="overflow-auto">
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('masterdata.fields.code') }}</th>
                    <th>{{ __('masterdata.fields.name') }}</th>
                    <th>{{ __('masterdata.fields.abn') }}</th>
                    <th>{{ __('masterdata.fields.leg_type') }}</th>
                    <th>{{ __('masterdata.fields.payment_terms') }}</th>
                    <th>{{ __('masterdata.fields.invoice_mode') }}</th>
                    <th class="num">{{ __('masterdata.fields.default_markup_percent') }}</th>
                    <th>{{ __('masterdata.fields.dispatch_cutoff_time') }}</th>
                    <th>{{ __('masterdata.fields.status') }}</th>
                    <th>{{ __('platform.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($clients as $c)
                    <tr>
                        <td>{{ $c->code }}</td>
                        <td>{{ $c->name }}</td>
                        <td>{{ $c->abn }}</td>
                        <td>{{ __('masterdata.leg_types.'.$c->leg_type) }}</td>
                        <td>{{ $c->payment_terms }}</td>
                        <td>{{ __('masterdata.invoice_modes.'.$c->invoice_mode) }}</td>
                        <td class="num">{{ $c->default_markup_percent }}%</td>
                        <td>{{ $c->dispatch_cutoff_time ? substr($c->dispatch_cutoff_time, 0, 5) : '—' }}</td>
                        <td>
                            <span class="badge" data-tone="{{ ['active' => 'ok', 'pending' => 'warn'][$c->status] ?? 'muted' }}">{{ __('masterdata.statuses.'.$c->status) }}</span>
                            @if ($c->standard_rate_card_id === null)<span class="badge" data-tone="warn" title="{{ __('masterdata.clients.no_standard_card') }}">{{ __('masterdata.clients.standard_card_missing') }}</span>@endif
                        </td>
                        <td>
                            <a href="{{ route('masterdata.clients.edit', $c) }}">{{ __('platform.common.edit') }}</a>
                            @if ($c->status === 'pending')
                                <form method="post" action="{{ route('masterdata.clients.approve', $c) }}" style="display:inline">@csrf <button type="submit" class="outline" style="padding:.1rem .6rem;margin:0">{{ __('masterdata.clients.approve') }}</button></form>
                            @endif
                            @if ($c->standard_rate_card_id === null)
                                @role('admin')
                                    <form method="post" action="{{ route('masterdata.clients.bind_standard_card', $c) }}" style="display:inline">@csrf <button type="submit" class="outline" style="padding:.1rem .6rem;margin:0">{{ __('masterdata.clients.bind_standard_card') }}</button></form>
                                @endrole
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-muted">{{ __('masterdata.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $clients->links() }}
@endsection
