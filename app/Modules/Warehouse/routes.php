<?php

use App\Modules\Warehouse\Http\Controllers\AsnController;
use App\Modules\Warehouse\Http\Controllers\GoodsReceiptController;
use App\Modules\Warehouse\Http\Controllers\LabelController;
use App\Modules\Warehouse\Http\Controllers\LocationController;
use App\Modules\Warehouse\Http\Controllers\OutboundController;
use App\Modules\Warehouse\Http\Controllers\PutawayController;
use App\Modules\Warehouse\Http\Controllers\ReceivingController;
use App\Modules\Warehouse\Http\Controllers\ReturnController;
use App\Modules\Warehouse\Http\Controllers\ScanController;
use App\Modules\Warehouse\Http\Controllers\SnapshotController;
use App\Modules\Warehouse\Http\Controllers\StockController;
use App\Modules\Warehouse\Http\Controllers\StocktakeController;
use App\Modules\Warehouse\Http\Controllers\TaskController;
use App\Modules\Warehouse\Http\Controllers\UnplannedReceivingController;
use App\Modules\Warehouse\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// contracts/routes.md — every Warehouse route lives under /warehouse with the "warehouse." name prefix.
Route::prefix('warehouse')->name('warehouse.')->group(function () {
    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator|dispatcher|customer_service|finance')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('/stock/{unit}', [StockController::class, 'show'])->name('stock.show');
        Route::get('/reservations', [StockController::class, 'reservations'])->name('reservations.index');
        Route::get('/asns', [AsnController::class, 'index'])->name('asns.index');
        Route::get('/asns/{asn}', [AsnController::class, 'show'])->name('asns.show')->whereNumber('asn');
        Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('/outbound', [OutboundController::class, 'index'])->name('outbound.index');
        Route::get('/outbound/waves/{wave}', [OutboundController::class, 'wave'])->name('outbound.waves.show')->whereNumber('wave');
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/{receipt}', [ReturnController::class, 'show'])->name('returns.show')->whereNumber('receipt');
        Route::get('/config/locations', [LocationController::class, 'index'])->name('locations.index');
        Route::get('/snapshots', [SnapshotController::class, 'index'])->name('snapshots.index');
        Route::get('/stocktakes', [StocktakeController::class, 'index'])->name('stocktakes.index');
        Route::get('/stocktakes/{stocktake}', [StocktakeController::class, 'show'])->name('stocktakes.show')->whereNumber('stocktake');
        Route::get('/scan', [ScanController::class, 'index'])->name('scan.index');
        Route::get('/scan/resolve', [ScanController::class, 'resolve'])->name('scan.resolve');
        Route::get('/labels/units', [LabelController::class, 'units'])->name('labels.units');
        Route::get('/labels/asn/{asn}', [LabelController::class, 'asn'])->name('labels.asn');
        Route::get('/labels/locations', [LabelController::class, 'locations'])->name('labels.locations');
        Route::post('/switch', [WarehouseController::class, 'switch'])->name('switch');
    });

    // 入库单 (goods receipt, CHANGE_REQUESTS #90): finance reads them too; the PDF is rendered live (open → 草稿).
    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator|customer_service|finance')->group(function () {
        Route::get('/receipts', [GoodsReceiptController::class, 'index'])->name('receipts.index');
        Route::get('/receipts/{receipt}', [GoodsReceiptController::class, 'show'])->name('receipts.show')->whereNumber('receipt');
        Route::get('/receipts/{receipt}/pdf', [GoodsReceiptController::class, 'pdf'])->name('receipts.pdf')->whereNumber('receipt');
    });

    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator|customer_service')->group(function () {
        Route::get('/asns/create', [AsnController::class, 'create'])->name('asns.create');
        Route::post('/asns', [AsnController::class, 'store'])->name('asns.store');
        Route::post('/asns/{asn}/lines', [AsnController::class, 'storeLine'])->name('asns.lines.store');
        Route::post('/asns/{asn}/import', [AsnController::class, 'import'])->name('asns.import');
        Route::post('/asns/{asn}/arrive', [AsnController::class, 'arrive'])->name('asns.arrive');
        Route::post('/asns/{asn}/confirm-unplanned', [AsnController::class, 'confirmUnplanned'])->name('asns.confirm_unplanned');
        Route::post('/asns/{asn}/generate-orders', [AsnController::class, 'generateOrders'])->name('asns.generate_orders');
        Route::get('/receiving', [ReceivingController::class, 'index'])->name('receiving.index'); // 待收货 worklist (the 收货 button itself is warehouse-roles only)
    });

    Route::middleware('role:admin|warehouse_supervisor|warehouse_operator')->group(function () {
        Route::get('/asns/{asn}/lines/{line}/receive', [ReceivingController::class, 'form'])->name('receiving.form');
        Route::post('/asns/{asn}/lines/{line}/receive', [ReceivingController::class, 'store'])->name('receiving.store');
        Route::get('/receiving/unplanned', [UnplannedReceivingController::class, 'form'])->name('receiving.unplanned.form'); // 无预报收货 (#91)
        Route::post('/receiving/unplanned', [UnplannedReceivingController::class, 'store'])->name('receiving.unplanned.store');
        Route::post('/receipts/{receipt}/complete', [GoodsReceiptController::class, 'complete'])->name('receipts.complete')->whereNumber('receipt'); // 入库完成
        Route::get('/putaway', [PutawayController::class, 'index'])->name('putaway.index');
        Route::post('/putaway/{unit}', [PutawayController::class, 'store'])->name('putaway.store');
        Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/config/locations', [LocationController::class, 'store'])->name('locations.store');
        Route::post('/config/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::get('/stocktakes/create', [StocktakeController::class, 'create'])->name('stocktakes.create');
        Route::post('/stocktakes', [StocktakeController::class, 'store'])->name('stocktakes.store');
        Route::post('/stocktakes/{stocktake}/lines/{line}', [StocktakeController::class, 'count'])->name('stocktakes.count');
        Route::post('/stocktakes/{stocktake}/scan', [StocktakeController::class, 'scan'])->name('stocktakes.scan');
        Route::post('/stocktakes/{stocktake}/close', [StocktakeController::class, 'close'])->name('stocktakes.close');
        Route::post('/stock/{unit}/move', [StockController::class, 'move'])->name('stock.move');
        Route::post('/stock/{unit}/quarantine', [StockController::class, 'quarantine'])->name('stock.quarantine');
        Route::post('/stock/{unit}/restore', [StockController::class, 'restore'])->name('stock.restore');
        Route::post('/outbound/waves', [OutboundController::class, 'release'])->name('outbound.waves.release');
        Route::post('/outbound/picks/{line}', [OutboundController::class, 'pick'])->name('outbound.pick');
        Route::get('/outbound/pack/{fulfilment}', [OutboundController::class, 'packForm'])->name('outbound.pack.form')->whereNumber('fulfilment');
        Route::post('/outbound/pack/{fulfilment}', [OutboundController::class, 'pack'])->name('outbound.pack')->whereNumber('fulfilment');
        Route::post('/outbound/dispatch/{fulfilment}', [OutboundController::class, 'dispatch'])->name('outbound.dispatch')->whereNumber('fulfilment');
        Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
        Route::post('/returns/{receipt}/lines/{line}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::post('/returns/{receipt}/complete-receiving', [ReturnController::class, 'completeReceiving'])->name('returns.complete_receiving');
        Route::post('/returns/{receipt}/lines/{line}/inspect', [ReturnController::class, 'inspect'])->name('returns.inspect');
        Route::post('/returns/{receipt}/complete-inspection', [ReturnController::class, 'completeInspection'])->name('returns.complete_inspection');
    });
});
