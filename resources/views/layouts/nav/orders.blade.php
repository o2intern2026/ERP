{{-- Owner: seat X1. Only the Orders module edits this include (contracts/routes.md). --}}
<li><a href="{{ route('orders.index') }}">{{ __('orders.nav') }}</a></li>
@role('admin|customer_service|dispatcher|warehouse_supervisor|finance')
    @php($pendingRequests = app(\App\Modules\Orders\Services\ClientRequestService::class)->pendingCount())
    <li><a href="{{ route('orders.requests.index') }}">{{ __('orders.requests.nav') }}@if ($pendingRequests > 0) <span class="badge" data-tone="warn">{{ $pendingRequests }}</span>@endif</a></li>
@endrole
@role('admin|customer_service|dispatcher|finance')
    <li><a href="{{ route('orders.queue') }}">{{ __('orders.queue.nav') }}</a></li>
    <li><a href="{{ route('orders.batches') }}">{{ __('orders.batches.nav') }}</a></li>
@endrole
@role('admin')
    <li><a href="{{ route('orders.api-tokens.index') }}">{{ __('orders.api.nav') }}</a></li>
@endrole
