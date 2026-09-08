{{-- Owner: seat X1. Only the Orders module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('orders.index') }}">{{ __('orders.nav') }}</a></li>
@role('admin|customer_service|dispatcher|finance')
    <li><a href="{{ route('orders.queue') }}">{{ __('orders.queue.nav') }}</a></li>
    <li><a href="{{ route('orders.batches') }}">{{ __('orders.batches.nav') }}</a></li>
@endrole
@role('admin')
    <li><a href="{{ route('orders.api-tokens.index') }}">{{ __('orders.api.nav') }}</a></li>
@endrole
