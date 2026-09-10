@extends('layouts.app')

@section('title', $user->exists ? __('platform.users.edit') : __('platform.users.create'))

@section('content')
    <h1>{{ $user->exists ? __('platform.users.edit') : __('platform.users.create') }}</h1>
    <form method="post" action="{{ $user->exists ? route('platform.users.update', $user) : route('platform.users.store') }}">
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <div class="grid">
            <label>{{ __('platform.users.name') }}<input type="text" name="name" value="{{ old('name', $user->name) }}" required></label>
            <label>{{ __('platform.users.email') }}<input type="email" name="email" value="{{ old('email', $user->email) }}" required></label>
        </div>
        <div class="grid">
            <label>{{ __('platform.users.password') }}
                <input type="password" name="password" autocomplete="new-password" @required(! $user->exists)>
                @if ($user->exists) <small>{{ __('platform.users.password_hint') }}</small> @endif
            </label>
            <label>{{ __('platform.users.role') }}
                <select name="role" required>
                    {{-- Audit 2026-09-10: without a placeholder the first option (管理员) was the silent default and `required` never fired. --}}
                    <option value="" @selected(old('role', $currentRole) === null)>{{ __('platform.users.role_placeholder') }}</option>
                    @foreach ($roles as $r)
                        <option value="{{ $r }}" @selected(old('role', $currentRole) === $r)>{{ __('platform.roles.'.$r) }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div class="grid">
            <label>{{ __('platform.users.client') }}
                <select name="client_id">
                    <option value="">—</option>
                    @foreach ($clients as $c)
                        <option value="{{ $c->id }}" @selected(old('client_id', $user->client_id) == $c->id)>{{ $c->code }} · {{ $c->name }}</option>
                    @endforeach
                </select>
                <small>{{ __('platform.users.client_hint') }}</small>
            </label>
            <label>
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->exists ? $user->is_active : true))> {{ __('platform.users.active') }}
            </label>
        </div>
        <button type="submit">{{ __('platform.common.save') }}</button>
        <a href="{{ route('platform.users.index') }}" class="secondary" role="button">{{ __('platform.common.cancel') }}</a>
    </form>
@endsection
