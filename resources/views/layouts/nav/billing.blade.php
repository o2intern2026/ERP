{{-- Owner: seat C. Only the Billing module edits this include (contracts/routes.md). --}}
@role('admin|finance')
    <li><a href="{{ route('billing.index') }}">{{ __('billing.nav') }}</a></li>
    <li><a href="{{ route('billing.unbilled') }}">{{ __('billing.nav_unbilled') }}</a></li>
    <li><a href="{{ route('billing.invoices.index') }}">{{ __('billing.nav_invoices') }}</a></li>
    <li><a href="{{ route('billing.receivables') }}">{{ __('billing.nav_receivables') }}</a></li>
    <li><a href="{{ route('billing.rate_cards.index') }}">{{ __('billing.nav_rate_cards') }}</a></li>
@endrole
@role('admin|finance|customer_service')
    <li><a href="{{ route('billing.quotes.index') }}">{{ __('billing.nav_quotes') }}</a></li>
@endrole
