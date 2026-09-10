<?php

namespace App\Modules\Warehouse;

use App\Modules\Warehouse\Console\ReconcileStockCommand;
use App\Modules\Warehouse\Console\SnapshotStockCommand;
use App\Modules\Warehouse\Consumers\OrderCancelledConsumer;
use App\Modules\Warehouse\Consumers\OrderConfirmedConsumer;
use App\Modules\Warehouse\Consumers\OrderReducedConsumer;
use App\Modules\Warehouse\Models\Asn;
use App\Modules\Warehouse\Models\AsnLine;
use App\Modules\Warehouse\Models\Container;
use App\Modules\Warehouse\Models\GoodsReceipt;
use App\Modules\Warehouse\Models\StockUnit;
use App\Modules\Warehouse\Services\StockService;
use App\Support\Contracts\StockService as StockServiceContract;
use App\Support\Outbox\ConsumerRegistry;
use App\Support\Search\SearchRegistry;
use Illuminate\Support\ServiceProvider;

class WarehouseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Real StockService (contracts/services.md §1) from M2; overrides FakeStockService.
        $this->app->singleton(StockServiceContract::class, StockService::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'warehouse');
        $this->loadMigrationsFrom(__DIR__.'/migrations');

        $registry = $this->app->make(ConsumerRegistry::class);
        $registry->register('order.confirmed', OrderConfirmedConsumer::class);
        $registry->register('order.cancelled', OrderCancelledConsumer::class);
        $registry->register('order.reduced', OrderReducedConsumer::class);

        // Role lists mirror routes.php: the read group for the module, a narrower one for 入库单 (no dispatcher) — audit 2026-09-10.
        $this->app->make(SearchRegistry::class)->register('warehouse', fn (string $q): array => array_merge(
            Asn::query()->where('asn_no', 'like', "%{$q}%")->limit(10)->get()->map(fn ($a) => ['type' => 'asn', 'label' => $a->asn_no, 'url' => route('warehouse.asns.show', $a), 'meta' => __('warehouse.asn_statuses.'.$a->status)])->all(),
            auth()->user()?->hasAnyRole(['admin', 'warehouse_supervisor', 'warehouse_operator', 'customer_service', 'finance'])
                ? GoodsReceipt::query()->where('receipt_no', 'like', "%{$q}%")->limit(10)->get()->map(fn ($r) => ['type' => 'goods_receipt', 'label' => $r->receipt_no, 'url' => route('warehouse.receipts.show', $r), 'meta' => __('warehouse.receipt_statuses.'.$r->status)])->all()
                : [],
            Container::query()->where('container_no', 'like', "%{$q}%")->limit(10)->get()->map(fn ($c) => ['type' => 'container', 'label' => $c->container_no, 'url' => route('warehouse.asns.show', $c->asn_id), 'meta' => __('warehouse.container_sizes.'.$c->size)])->all(),
            AsnLine::query()->where('consignment_mark', 'like', "%{$q}%")->limit(10)->get()->map(fn ($l) => ['type' => 'consignment_mark', 'label' => (string) $l->consignment_mark, 'url' => route('warehouse.asns.show', $l->asn_id).'#line-'.$l->id, 'meta' => $l->description])->all(),
            StockUnit::query()->where('label_code', 'like', "%{$q}%")->limit(10)->get()->map(fn ($u) => ['type' => 'stock_unit', 'label' => $u->label_code, 'url' => route('warehouse.stock.show', $u), 'meta' => ($u->location?->full_code ?? '—').' · '.$u->qty_on_hand])->all(),
        ), ['admin', 'warehouse_supervisor', 'warehouse_operator', 'dispatcher', 'customer_service', 'finance']);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileStockCommand::class, SnapshotStockCommand::class]);
        }
    }
}
