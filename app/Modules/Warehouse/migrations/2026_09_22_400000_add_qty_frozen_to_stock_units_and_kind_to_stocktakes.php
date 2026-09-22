<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #142 (lead decision 2026-09-22, OUTBOUND-08): a short pick with reason 找不到 no longer returns the shortfall to
 * available stock. The cartons stay on the unit as `qty_frozen` — excluded from available = on_hand − reserved − frozen — until the
 * warehouse's open 差异盘点 (stocktakes.kind = discrepancy, one line per unit) is counted: counted ≥ expected releases the frozen
 * quantity, counted < expected adjusts on close and clears it. Existing rows: qty_frozen 0, kind `full`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', function (Blueprint $table): void {
            $table->integer('qty_frozen')->default(0)->after('qty_reserved');
        });

        Schema::table('stocktakes', function (Blueprint $table): void {
            $table->string('kind', 20)->default('full')->after('status');
            $table->index(['warehouse_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('stocktakes', function (Blueprint $table): void {
            $table->dropIndex(['warehouse_id', 'kind', 'status']);
            $table->dropColumn('kind');
        });
        Schema::table('stock_units', fn (Blueprint $table) => $table->dropColumn('qty_frozen'));
    }
};
