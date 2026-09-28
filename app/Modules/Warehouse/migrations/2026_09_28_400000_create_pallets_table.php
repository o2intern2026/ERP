<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #166 托盘牌号 (LPN): a physical pallet is its own record — one barcode (P<id>), one location, one class / source /
 * dims, and any number of stock units of one client + one Job on it (mixed pallets). Every existing `unit_type = pallet` stock unit
 * becomes a pallet of its own (P-000001 …); snapshots learn their pallet so weekly storage counts pallets, not lines on pallets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pallets', function (Blueprint $table) {
            $table->id();
            $table->string('pallet_no', 30)->unique();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('job_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained('locations');
            $table->string('pallet_class', 20)->nullable();
            $table->string('pallet_class_overridden_reason', 255)->nullable();
            $table->string('pallet_source', 20)->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->decimal('weight_kg', 9, 3)->nullable();
            $table->string('status', 12)->default('in_use');
            $table->boolean('putaway_completed')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
            $table->index(['client_id', 'job_id']);
        });
        Schema::table('stock_units', function (Blueprint $table) {
            $table->foreignId('pallet_id')->nullable()->after('location_id')->constrained('pallets')->nullOnDelete();
        });
        Schema::table('stock_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('pallet_id')->nullable()->after('stock_unit_id')->index();
        });

        // Backfill: one pallet per pallet-type unit, numbered in receiving order; snapshots point at the pallet of their unit.
        $seq = 0;
        foreach (DB::table('stock_units')->where('unit_type', 'pallet')->orderBy('id')->get() as $unit) {
            $seq++;
            $palletId = DB::table('pallets')->insertGetId([
                'pallet_no' => sprintf('P-%06d', $seq),
                'warehouse_id' => $unit->warehouse_id,
                'client_id' => $unit->client_id,
                'job_id' => $unit->job_id,
                'location_id' => $unit->location_id,
                'pallet_class' => $unit->pallet_class,
                'pallet_class_overridden_reason' => $unit->pallet_class_overridden_reason,
                'pallet_source' => $unit->pallet_source,
                'length_mm' => $unit->length_mm,
                'width_mm' => $unit->width_mm,
                'height_mm' => $unit->height_mm,
                'weight_kg' => $unit->weight_kg,
                'status' => (int) $unit->qty_on_hand > 0 ? 'in_use' : 'empty',
                'putaway_completed' => (bool) $unit->putaway_completed,
                'received_at' => $unit->received_at,
                'created_at' => $unit->created_at ?? now(),
                'updated_at' => now(),
            ]);
            DB::table('stock_units')->where('id', $unit->id)->update(['pallet_id' => $palletId]);
        }
        DB::statement('UPDATE stock_snapshots s JOIN stock_units u ON u.id = s.stock_unit_id SET s.pallet_id = u.pallet_id WHERE u.pallet_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('stock_snapshots', fn (Blueprint $table) => $table->dropColumn('pallet_id'));
        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pallet_id');
        });
        Schema::dropIfExists('pallets');
    }
};
