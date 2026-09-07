<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Daily stock snapshot — the basis for storage charges (ERP_PLAN §4.2, §4.8): one row per unit per day.
        Schema::create('stock_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('stock_unit_id')->constrained();
            $table->foreignId('asn_line_id')->constrained();
            $table->string('unit_type', 10);
            $table->string('pallet_class', 20)->nullable();
            $table->string('pallet_source', 20)->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_type', 20)->nullable();
            $table->string('condition', 20);
            $table->integer('qty_on_hand');
            $table->integer('qty_reserved');
            $table->timestamp('created_at');
            $table->unique(['snapshot_date', 'stock_unit_id']);
            $table->index(['snapshot_date', 'client_id']);
        });

        // Stocktake: scope = warehouse, optionally one client or one location; variances need a reason (§4.4 盘点).
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('stocktake_no', 20)->unique();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('counting')->index();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_unit_id')->constrained();
            $table->integer('expected_qty');
            $table->integer('counted_qty')->nullable();
            $table->integer('variance')->nullable();
            $table->string('reason')->nullable();
            $table->boolean('scanned')->default(false);
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('adjusted_at')->nullable();
            $table->unique(['stocktake_id', 'stock_unit_id']);
        });

        // Serial numbers scanned during a `scanning` VAS task (§4.4 VAS, charge WH-SCAN-SERIAL).
        Schema::create('scan_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('warehouse_tasks')->cascadeOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_no', 120);
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->index(['task_id', 'serial_no']);
        });

        Schema::table('stock_units', function (Blueprint $table) {
            $table->string('condition_reason')->nullable()->after('condition');
            $table->timestamp('condition_changed_at')->nullable()->after('condition_reason');
        });
    }

    public function down(): void
    {
        Schema::table('stock_units', fn (Blueprint $table) => $table->dropColumn(['condition_reason', 'condition_changed_at']));
        Schema::dropIfExists('scan_records');
        Schema::dropIfExists('stocktake_lines');
        Schema::dropIfExists('stocktakes');
        Schema::dropIfExists('stock_snapshots');
    }
};
