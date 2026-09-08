{{-- Frozen zone (seat C). One include per module; each seat edits only its own layouts/nav/<module>.blade.php.
     Staff see one dropdown per module (an include that renders nothing for the role is skipped); client users see the portal links flat. --}}
<nav class="container erp-nav">
    <ul>
        <li><strong><a href="{{ url('/') }}">{{ config('app.name') }}</a></strong></li>
    </ul>
    @auth
        @if (! auth()->user()->isClientUser())
            <ul>
                @foreach (['platform' => ['layouts.nav.platform', 'layouts.nav.masterdata'], 'orders' => ['layouts.nav.orders'], 'warehouse' => ['layouts.nav.warehouse'], 'transport' => ['layouts.nav.transport'], 'billing' => ['layouts.nav.billing'], 'reports' => ['layouts.nav.reports']] as $group => $includes)
                    @php($items = trim(collect($includes)->map(fn ($view) => view($view)->render())->implode('')))
                    @if ($items !== '')
                        @php($active = $group === 'platform' ? request()->routeIs('platform.*', 'masterdata.*') : request()->routeIs($group.'.*'))
                        <li>
                            <details class="dropdown">
                                <summary @if ($active) class="active" @endif>{{ __('platform.nav_groups.'.$group) }}</summary>
                                <ul dir="ltr">{!! $items !!}</ul>
                            </details>
                        </li>
                    @endif
                @endforeach
            </ul>
        @else
            <ul>
                @include('layouts.nav.portal')
            </ul>
        @endif
        <ul>
            @if (! auth()->user()->isClientUser())
                <li>
                    <form method="get" action="{{ route('platform.search') }}" class="inline">
                        <input type="search" name="q" placeholder="{{ __('platform.search_placeholder') }}" value="{{ request()->routeIs('platform.search') ? request('q') : '' }}" class="erp-search">
                    </form>
                </li>
            @endif
            <li class="text-muted erp-user">{{ auth()->user()->name }}</li>
            <li>
                <form method="post" action="{{ route('platform.logout') }}" class="inline">
                    @csrf
                    <button type="submit" class="secondary outline erp-logout">{{ __('platform.auth.logout') }}</button>
                </form>
            </li>
        </ul>
    @endauth
</nav>
