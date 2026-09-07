{{-- Owner: seat C. Only the Warehouse module edits this include (contracts/routes.md). --}}
@role('admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance')
    <li><a href="{{ route('warehouse.asns.index') }}">{{ __('warehouse.nav_asns') }}</a></li>
    <li><a href="{{ route('warehouse.index') }}">{{ __('warehouse.nav') }}</a></li>
    @role('admin|warehouse_supervisor|warehouse_operator')
        <li><a href="{{ route('warehouse.putaway.index') }}">{{ __('warehouse.nav_putaway') }}</a></li>
        <li><a href="{{ route('warehouse.tasks.index') }}">{{ __('warehouse.nav_tasks') }}</a></li>
    @endrole
@endrole
