<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #167 跨仓调拨 + 计费仓库: a transfer order moves pallets / units from one warehouse to another (draft → dispatched →
 * received, then the destination's ordinary putaway); the billing warehouse of stock is the warehouse the client booked — it only
 * follows the goods when the client asked for the move (charge_to = client). Backfill: every unit's billing warehouse = its ASN's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_units', fn (Blueprint $table) => $table->foreignId('billing_warehouse_id')->nullable()->after('warehouse_id')->constrained('warehouses'));
        Schema::table('pallets', fn (Blueprint $table) => $table->foreignId('billing_warehouse_id')->nullable()->after('warehouse_id')->constrained('warehouses'));
        Schema::table('stock_snapshots', fn (Blueprint $table) => $table->unsignedBigInteger('billing_warehouse_id')->nullable()->after('warehouse_id'));

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 30)->unique();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('job_id')->constrained();
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->string('charge_to', 12)->default('internal');
            $table->string('status', 12)->default('draft');
            $table->string('vehicle', 100)->nullable();
            $table->string('driver_name', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('dispatched_by')->nullable()->constrained('users');
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users');
            $table->timestamp('received_at')->nullable();
            $table->foreignId('receiving_location_id')->nullable()->constrained('locations');
            $table->timestamps();
            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
            $table->index(['client_id', 'status']);
        });
        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('stock_unit_id')->constrained('stock_units');
            $table->foreignId('pallet_id')->nullable()->constrained('pallets');
            $table->unsignedInteger('qty')->default(0);
            $table->foreignId('from_location_id')->nullable()->constrained('locations');
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'stock_unit_id']);
        });

        DB::statement('UPDATE stock_units u JOIN asn_lines l ON l.id = u.asn_line_id JOIN asns a ON a.id = l.asn_id SET u.billing_warehouse_id = a.warehouse_id WHERE u.billing_warehouse_id IS NULL');
        DB::statement('UPDATE pallets p JOIN (SELECT pallet_id, MIN(billing_warehouse_id) AS w FROM stock_units WHERE pallet_id IS NOT NULL GROUP BY pallet_id) x ON x.pallet_id = p.id SET p.billing_warehouse_id = x.w WHERE p.billing_warehouse_id IS NULL');
        DB::statement('UPDATE pallets SET billing_warehouse_id = warehouse_id WHERE billing_warehouse_id IS NULL');
        DB::statement('UPDATE stock_snapshots s JOIN stock_units u ON u.id = s.stock_unit_id SET s.billing_warehouse_id = u.billing_warehouse_id WHERE s.billing_warehouse_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::table('stock_snapshots', fn (Blueprint $table) => $table->dropColumn('billing_warehouse_id'));
        Schema::table('pallets', fn (Blueprint $table) => $table->dropConstrainedForeignId('billing_warehouse_id'));
        Schema::table('stock_units', fn (Blueprint $table) => $table->dropConstrainedForeignId('billing_warehouse_id'));
    }
};
