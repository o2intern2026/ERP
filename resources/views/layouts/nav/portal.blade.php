{{-- Owner: seat X1. Only the Portal module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('portal.index') }}">{{ __('portal.nav') }}</a></li>
@if (auth()->user()->isClientUser())
    <li><a href="{{ route('portal.orders.create') }}">{{ __('portal.actions.create') }}</a></li>
    <li><a href="{{ route('portal.asns.index') }}">{{ __('portal.asns.nav') }}</a></li>
    <li><a href="{{ route('portal.stock.index') }}">{{ __('portal.stock.nav') }}</a></li>
    <li><a href="{{ route('portal.invoices.index') }}">{{ __('portal.invoices.nav') }}</a></li>
    <li><a href="{{ route('portal.reports.index') }}">{{ __('portal.reports.nav') }}</a></li>
@endif
