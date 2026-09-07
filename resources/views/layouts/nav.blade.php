{{-- Frozen zone. One include per module; each seat edits only its own layouts/nav/<module>.blade.php. --}}
<nav class="container">
    <ul>
        <li><strong><a href="{{ url('/') }}">{{ config('app.name') }}</a></strong></li>
    </ul>
    @auth
        <ul>
            @if (! auth()->user()->isClientUser())
                @include('layouts.nav.platform')
                @include('layouts.nav.masterdata')
                @include('layouts.nav.warehouse')
                @include('layouts.nav.billing')
                @include('layouts.nav.orders')
                @include('layouts.nav.reports')
                @include('layouts.nav.transport')
            @endif
            @include('layouts.nav.portal')
        </ul>
        <ul>
            <li class="text-muted">{{ auth()->user()->name }}</li>
            <li>
                <form method="post" action="{{ route('platform.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="secondary outline">{{ __('platform.auth.logout') }}</button>
                </form>
            </li>
        </ul>
    @endauth
</nav>
