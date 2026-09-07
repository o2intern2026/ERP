{{-- Owner: seat C. Only the Platform module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('platform.index') }}">{{ __('platform.nav') }}</a></li>
<li><a href="{{ route('platform.exceptions.index') }}">{{ __('platform.nav_exceptions') }}</a></li>
<li><a href="{{ route('platform.documents.index') }}">{{ __('platform.nav_documents') }}</a></li>
<li><a href="{{ route('platform.approvals.index') }}">{{ __('platform.nav_approvals') }}</a></li>
@role('admin')
    <li><a href="{{ route('platform.users.index') }}">{{ __('platform.nav_users') }}</a></li>
    <li><a href="{{ route('platform.integration.index') }}">{{ __('platform.nav_integration') }}</a></li>
    <li><a href="{{ route('platform.activity.index') }}">{{ __('platform.nav_activity') }}</a></li>
    <li><a href="{{ route('platform.webhooks.index') }}">{{ __('platform.nav_webhooks') }}</a></li>
@endrole
