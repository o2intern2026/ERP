@extends('layouts.app')

@section('title', __('platform.users.title'))

@section('content')
    <header class="grid">
        <h1>{{ __('platform.users.title') }}</h1>
        <p style="text-align:right"><a role="button" href="{{ route('platform.users.create') }}">{{ __('platform.users.create') }}</a></p>
    </header>
    <div class="overflow-auto">
        <table class="dense">
            <thead>
                <tr>
                    <th>{{ __('platform.users.name') }}</th>
                    <th>{{ __('platform.users.email') }}</th>
                    <th>{{ __('platform.users.role') }}</th>
                    <th>{{ __('platform.users.client') }}</th>
                    <th>{{ __('platform.users.status') }}</th>
                    <th>{{ __('platform.users.last_login') }}</th>
                    <th>{{ __('platform.common.actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->email }}</td>
                        <td>{{ $u->roles->first() ? __('platform.roles.'.$u->roles->first()->name) : '—' }}</td>
                        <td>{{ $u->client?->name ?? '—' }}</td>
                        <td><span class="badge" data-tone="{{ $u->is_active ? 'ok' : 'muted' }}">{{ $u->is_active ? __('platform.users.active') : __('platform.users.inactive') }}</span></td>
                        <td>{{ $u->last_login_at?->format('Y-m-d H:i') ?? __('platform.users.never') }}</td>
                        <td><a href="{{ route('platform.users.edit', $u) }}">{{ __('platform.common.edit') }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
@endsection
