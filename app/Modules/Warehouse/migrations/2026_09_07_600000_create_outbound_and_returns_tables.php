<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // B4 waves: a release of confirmed + reserved fulfilments into pick tasks (ERP_PLAN §4.2 waves).
        Schema::create('waves', function (Blueprint $table) {
            $table->id();
            $table->string('wave_no', 20)->unique();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('status', 20)->default('released')->index();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('warehouse_tasks', function (Blueprint $table) {
            $table->foreignId('wave_id')->nullable()->after('fulfilment_id')->constrained()->nullOnDelete();
        });
        Schema::table('warehouse_task_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('order_line_id')->nullable()->after('asn_line_id'); // order_lines is an X1 table (no FK)
        });

        // Packages built at packing; every package prints one carton label (WH-LABEL-OUT) and feeds TMS quoting.
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fulfilment_id')->index(); // X1 table (no FK)
            $table->unsignedBigInteger('order_id')->index();     // X1 table (no FK)
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('client_id')->constrained();
            $table->string('package_type', 30);
            $table->decimal('weight_kg', 10, 3);
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->string('carton_label', 40)->unique();
            $table->timestamps();
        });

        // Handover record: packed (ready) and dispatched (left the warehouse) are two timestamps (§4.3 rule 5).
        Schema::create('outbound_dispatches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fulfilment_id')->unique();
            $table->unsignedBigInteger('order_id')->index();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('client_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->unsignedInteger('pallet_count')->default(0);
            $table->unsignedInteger('package_count')->default(0);
            $table->string('handed_to', 20);
            $table->unsignedBigInteger('shipment_id')->nullable(); // X2 table (no FK)
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at');
            $table->timestamps();
        });

        // B13 returns: received first, stock only after inspection (§4.3 rule 7).
        Schema::create('return_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 20)->unique();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('client_id')->constrained();
            $table->unsignedBigInteger('return_order_id')->nullable()->index();
            $table->unsignedBigInteger('original_order_id')->index();
            $table->unsignedBigInteger('original_shipment_id')->nullable();
            $table->unsignedBigInteger('return_shipment_id')->nullable();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('status', 20)->default('expected')->index();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('return_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('original_order_line_id');
            $table->foreignId('asn_line_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('original_fulfilment_id')->nullable();
            $table->string('description')->nullable();
            $table->unsignedInteger('expected_qty')->default(0);
            $table->unsignedInteger('received_qty')->default(0);
            $table->string('condition', 20)->nullable();
            $table->string('disposition', 20)->nullable();
            $table->foreignId('stock_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_receipt_lines');
        Schema::dropIfExists('return_receipts');
        Schema::dropIfExists('outbound_dispatches');
        Schema::dropIfExists('packages');
        Schema::table('warehouse_task_lines', fn (Blueprint $table) => $table->dropColumn('order_line_id'));
        Schema::table('warehouse_tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('wave_id'));
        Schema::dropIfExists('waves');
    }
};
