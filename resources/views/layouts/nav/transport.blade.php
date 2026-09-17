{{-- Owner: seat X2. Only the Transport module edits this include (contracts/routes.md). --}}
{{-- 2026-09-10 audit: each link is gated by the roles its controller accepts (班次 → DeliveryRunController, 承运商对账 → CarrierInvoiceController, 司机 → DriverController); 异常 has no role check server side. --}}
{{-- CHANGE_REQUESTS #130 (lead 2026-09-17): the driver (transport_operator) executes, the dispatcher plans — no 运输 board or 承运商对账 for drivers; 班次 stays and lists only their own runs. --}}
@unlessrole('transport_operator')
    <li><a href="{{ route('transport.index') }}">{{ __('transport.nav') }}</a></li>
@endunlessrole
@role('admin|customer_service|dispatcher|transport_operator')
    <li><a href="{{ route('transport.runs.index') }}">{{ __('transport.runs.nav') }}</a></li>
@endrole
@role('admin|finance')
    <li><a href="{{ route('transport.carrier-invoices.index') }}">{{ __('transport.reconciliation.nav') }}</a></li>
@endrole
@role('transport_operator')
    <li><a href="{{ route('transport.driver') }}">{{ __('transport.driver_nav') }}</a></li>
@endrole
<li><a href="{{ route('platform.exceptions.index', ['source_module' => 'transport']) }}">{{ __('transport.exceptions.nav') }}</a></li>
