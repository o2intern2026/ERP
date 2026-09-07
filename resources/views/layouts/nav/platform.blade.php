{{-- Owner: seat C. Only the Platform module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('platform.index') }}">{{ __('platform.nav') }}</a></li>
@role('admin')
    <li><a href="{{ route('platform.users.index') }}">{{ __('platform.nav_users') }}</a></li>
    <li><a href="{{ route('platform.integration.index') }}">{{ __('platform.nav_integration') }}</a></li>
@endrole
