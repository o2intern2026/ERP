{{-- Owner: seat X1. Only the Portal module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('portal.index') }}">{{ __('portal.nav') }}</a></li>
@if (auth()->user()->isClientUser())
    <li><a href="{{ route('portal.orders.create') }}">{{ __('portal.actions.create') }}</a></li>
@endif
