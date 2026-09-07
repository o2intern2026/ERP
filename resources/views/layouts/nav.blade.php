{{-- Frozen zone. One include per module; each seat edits only its own layouts/nav/<module>.blade.php. --}}
<nav class="container">
    <ul>
        <li><strong><a href="{{ url('/') }}">{{ config('app.name') }}</a></strong></li>
    </ul>
    <ul>
        @include('layouts.nav.platform')
        @include('layouts.nav.masterdata')
        @include('layouts.nav.warehouse')
        @include('layouts.nav.billing')
        @include('layouts.nav.orders')
        @include('layouts.nav.portal')
        @include('layouts.nav.reports')
        @include('layouts.nav.transport')
    </ul>
</nav>
