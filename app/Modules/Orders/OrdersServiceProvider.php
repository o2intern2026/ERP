<?php

namespace App\Modules\Orders;

use App\Modules\Orders\Consumers\AsnPutawayCompletedConsumer;
use App\Modules\Orders\Consumers\DeliveryPodCapturedConsumer;
use App\Modules\Orders\Consumers\OutboundDispatchedConsumer;
use App\Modules\Orders\Consumers\OutboundPackedConsumer;
use App\Modules\Orders\Consumers\ReturnInspectedConsumer;
use App\Modules\Orders\Consumers\StockReservationFailedConsumer;
use App\Modules\Orders\Consumers\StockReservedConsumer;
use App\Modules\Orders\Consumers\TaskCompletedConsumer;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\AsnOrderService;
use App\Modules\Orders\Services\SpreadsheetManifestParser;
use App\Support\Contracts\ManifestParser;
use App\Support\Contracts\OrderService;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Search\SearchRegistry;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real A4 services since M3 (integration by C, 2026-09-07): the Fakes for these two contracts are retired.
        $this->app->singleton(ManifestParser::class, SpreadsheetManifestParser::class);
        $this->app->singleton(OrderService::class, AsnOrderService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'orders');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $registry = $this->app->make(ConsumerRegistry::class);
        $registry->register('stock.reserved', StockReservedConsumer::class);
        $registry->register('stock.reservation_failed', StockReservationFailedConsumer::class);
        $registry->register('asn.putaway_completed', AsnPutawayCompletedConsumer::class);
        $registry->register('delivery.pod_captured', DeliveryPodCapturedConsumer::class);
        // Block 2: Warehouse / Transport progress and the return chain drive the order status (§4.3 rule 6, enums.md §3 rules).
        $registry->register('task.completed', TaskCompletedConsumer::class);
        $registry->register('outbound.packed', OutboundPackedConsumer::class);
        $registry->register('outbound.dispatched', OutboundDispatchedConsumer::class);
        $registry->register('return.inspected', ReturnInspectedConsumer::class);

        $this->app->make(SearchRegistry::class)->register('orders', fn (string $q): array => Order::query()->with('client')
            ->where(fn ($w) => $w->where('order_no', 'like', "%{$q}%")->orWhere('external_ref', 'like', "%{$q}%")->orWhere('consignment_mark', 'like', "%{$q}%")->orWhere('fba_reference', 'like', "%{$q}%"))
            ->limit(20)->get()
            ->map(fn ($o) => ['type' => 'order', 'label' => $o->order_no, 'url' => route('orders.show', $o), 'meta' => $o->client->name.' · '.__('orders.statuses.operational.'.$o->operational_status)])->all());
    }
}
