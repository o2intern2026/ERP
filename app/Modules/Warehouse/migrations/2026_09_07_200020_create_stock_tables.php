<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock unit = client + goods line + packaging unit + location (ERP_PLAN §4.2). qty_* are a projection of stock_ledger.
        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('asn_line_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('unit_type', 10);
            $table->string('label_code', 40)->unique();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('qty_on_hand')->default(0);
            $table->integer('qty_reserved')->default(0);
            $table->integer('qty_inbound')->default(0);
            $table->string('pallet_class', 20)->nullable();
            $table->string('pallet_class_overridden_reason')->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->string('pallet_source', 20)->nullable();
            $table->string('condition', 20)->default('good');
            $table->boolean('putaway_completed')->default(false); // available only after putaway (§4.3 rule 2)
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'asn_line_id']);
            $table->index(['warehouse_id', 'condition']);
        });

        // Append-only ledger: the single source of truth (§4.3 rule 9). Balances live on stock_units and are reconciled by stock:reconcile.
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_unit_id')->constrained();
            $table->string('movement_type', 20);
            $table->integer('qty');
            $table->integer('qty_before');
            $table->integer('qty_after');
            $table->uuid('movement_group_id')->nullable()->index();
            $table->foreignId('from_stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('to_stock_unit_id')->nullable()->constrained('stock_units');
            $table->foreignId('from_location_id')->nullable()->constrained('locations');
            $table->foreignId('to_location_id')->nullable()->constrained('locations');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['source_type', 'source_id']);
        });

        // Reservation is a quantity, not a condition (§4.3 rule 1); one row per order line × stock unit.
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();       // orders is an X1 table (no FK)
            $table->unsignedBigInteger('order_line_id')->index();
            $table->foreignId('stock_unit_id')->constrained();
            $table->integer('qty');
            $table->string('status', 20)->default('active');
            $table->timestamp('created_at');
            $table->timestamp('released_at')->nullable();
            $table->string('released_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('stock_units');
    }
};
