@extends('layouts.app')

@section('title', __('masterdata.clients.title'))

@section('content')
    <h1>{{ __('masterdata.title') }}</h1>
    @include('masterdata::partials.tabs', ['active' => 'clients'])
    <p style="text-align:right"><a role="button" href="{{ route('masterdata.clients.create') }}">{{ __('masterdata.clients.create') }}</a></p>
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
                        <td><span class="badge" data-tone="{{ $c->status === 'active' ? 'ok' : 'muted' }}">{{ __('masterdata.statuses.'.$c->status) }}</span></td>
                        <td><a href="{{ route('masterdata.clients.edit', $c) }}">{{ __('platform.common.edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="text-muted">{{ __('masterdata.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $clients->links() }}
@endsection
