{{-- Owner: seat C. Only the Warehouse module edits this include (contracts/routes.md). --}}
@role('admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance')
    <li><a href="{{ route('warehouse.asns.index') }}">{{ __('warehouse.nav_asns') }}</a></li>
    <li><a href="{{ route('warehouse.index') }}">{{ __('warehouse.nav') }}</a></li>
    @role('admin|warehouse_supervisor|warehouse_operator')
        <li><a href="{{ route('warehouse.putaway.index') }}">{{ __('warehouse.nav_putaway') }}</a></li>
        <li><a href="{{ route('warehouse.tasks.index') }}">{{ __('warehouse.nav_tasks') }}</a></li>
        <li><a href="{{ route('warehouse.outbound.index') }}">{{ __('warehouse.nav_outbound') }}</a></li>
        <li><a href="{{ route('warehouse.returns.index') }}">{{ __('warehouse.nav_returns') }}</a></li>
        <li><a href="{{ route('warehouse.stocktakes.index') }}">{{ __('warehouse.nav_stocktakes') }}</a></li>
        <li><a href="{{ route('warehouse.scan.index') }}">{{ __('warehouse.nav_scan') }}</a></li>
    @endrole
    <li><a href="{{ route('warehouse.snapshots.index') }}">{{ __('warehouse.nav_snapshots') }}</a></li>
    @php($navWarehouses = \App\Modules\Warehouse\Models\Warehouse::query()->where('active', true)->orderBy('code')->get(['id', 'code']))
    @if ($navWarehouses->count() > 1)
        <li>
            <form method="post" action="{{ route('warehouse.switch') }}" class="inline">
                @csrf
                <select name="warehouse_id" aria-label="{{ __('warehouse.warehouses.switch') }}" onchange="this.form.submit()" style="width:auto;padding:.2rem 2rem .2rem .5rem;margin:0">
                    <option value="">{{ __('warehouse.warehouses.all') }}</option>
                    @foreach ($navWarehouses as $w)<option value="{{ $w->id }}" @selected(\App\Modules\Warehouse\Services\WarehouseContext::currentId() === $w->id)>{{ $w->code }}</option>@endforeach
                </select>
            </form>
        </li>
    @endif
@endrole
