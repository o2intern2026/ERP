@extends('layouts.app')

@section('title', __('masterdata.suppliers.title'))

@section('content')
    <h1>{{ __('masterdata.title') }}</h1>
    @include('masterdata::partials.tabs', ['active' => 'suppliers'])
    <p style="text-align:right"><a role="button" href="{{ route('masterdata.suppliers.create') }}">{{ __('masterdata.suppliers.create') }}</a></p>
    <div class="overflow-auto">
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('masterdata.fields.code') }}</th>
                    <th>{{ __('masterdata.fields.name') }}</th>
                    <th>{{ __('masterdata.fields.abn') }}</th>
                    <th>{{ __('masterdata.fields.contact_name') }}</th>
                    <th>{{ __('masterdata.fields.contact_phone') }}</th>
                    <th>{{ __('masterdata.fields.status') }}</th>
                    <th>{{ __('platform.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $row)
                    <tr>
                        <td>{{ $row->code }}</td>
                        <td>{{ $row->name }}</td>
                        <td>{{ $row->abn }}</td>
                        <td>{{ $row->contact_name }}</td>
                        <td>{{ $row->contact_phone }}</td>
                        <td><span class="badge" data-tone="{{ $row->status === 'active' ? 'ok' : 'muted' }}">{{ __('masterdata.statuses.'.$row->status) }}</span></td>
                        <td><a href="{{ route('masterdata.suppliers.edit', $row) }}">{{ __('platform.common.edit') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">{{ __('masterdata.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $suppliers->links() }}
@endsection
