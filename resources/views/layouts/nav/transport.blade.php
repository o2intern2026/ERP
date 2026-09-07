{{-- Owner: seat X2. Only the Transport module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('transport.index') }}">{{ __('transport.nav') }}</a></li>
<li><a href="{{ route('transport.runs.index') }}">{{ __('transport.runs.nav') }}</a></li>
<li><a href="{{ route('transport.carrier-invoices.index') }}">{{ __('transport.reconciliation.nav') }}</a></li>
<li><a href="{{ route('transport.driver') }}">{{ __('transport.driver_nav') }}</a></li>
<li><a href="{{ route('platform.exceptions.index', ['source_module' => 'transport']) }}">{{ __('transport.exceptions.nav') }}</a></li>
