<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inbound master document (ERP_PLAN §4.2). Container is an optional child with basic fields only.
        Schema::create('asns', function (Blueprint $table) {
            $table->id();
            $table->string('asn_no', 20)->unique();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('client_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->date('expected_date')->nullable();
            $table->string('inbound_type', 20);
            $table->string('status', 20)->default('booked')->index();
            $table->string('created_by_type', 20)->default('coordinator');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('unplanned')->default(false);
            $table->boolean('unplanned_confirmed')->default(false);
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('putaway_completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asn_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('jobs');
            $table->string('container_no', 20);
            $table->string('size', 2);
            $table->string('unpack_mode', 10);
            $table->decimal('gross_weight_kg', 10, 3)->nullable();
            $table->unsignedInteger('line_count')->default(0); // derived from asn_lines
            $table->timestamps();
            $table->index('container_no');
        });

        // Goods lines = the identity of stock (no SKU master). Consignee fields kept for B2c order generation.
        Schema::create('asn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asn_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_id')->nullable()->constrained()->nullOnDelete();
            $table->string('consignment_mark')->nullable()->index();
            $table->string('deliver_to_name')->nullable();
            $table->string('deliver_to_phone', 40)->nullable();
            $table->string('deliver_to_address')->nullable();
            $table->string('deliver_to_suburb', 100)->nullable();
            $table->string('deliver_to_state', 3)->nullable();
            $table->string('deliver_to_postcode', 10)->nullable();
            $table->string('fba_reference', 60)->nullable();
            $table->string('description');
            $table->string('package_type', 40)->nullable();
            $table->unsignedInteger('expected_cartons')->default(0);
            $table->unsignedInteger('received_cartons')->default(0);
            $table->unsignedInteger('damaged_cartons')->default(0);
            $table->string('variance_reason')->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->decimal('cbm', 10, 4)->nullable();
            $table->unsignedBigInteger('order_line_id')->nullable()->index(); // set by B2c; order_lines is an X1 table (no FK)
            $table->timestamps();
        });

        Schema::create('asn_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asn_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();
            $table->json('warnings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asn_imports');
        Schema::dropIfExists('asn_lines');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('asns');
    }
};
