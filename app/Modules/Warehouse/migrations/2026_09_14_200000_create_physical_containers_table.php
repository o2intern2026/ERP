<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CHANGE_REQUESTS #122: one physical box shared by the container rows of several ASNs / clients (拼柜 / LCL). The box is the
 * object the coordinator links members to, the devanning task binds to, and the devanning / cartage fee is split over.
 * No client_id (it spans clients) → staff-only screens, never queried from the portal. Basic fields only, no seals / customs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_containers', function (Blueprint $table): void {
            $table->id();
            $table->string('container_no', 20)->index();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('size', 2);
            $table->string('unpack_mode', 10);
            $table->decimal('gross_weight_kg', 10, 3)->nullable();
            $table->string('consolidation', 3)->default('fcl'); // derived: distinct clients of the members (1 → fcl, > 1 → lcl)
            $table->string('allocation_basis', 20)->default('cartons_received');
            $table->boolean('cartage_by_us')->default(false);
            $table->boolean('sideloader_required')->default(false);
            $table->date('eta_date')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('devanned_at')->nullable();
            $table->string('status', 20)->default('expected');
            $table->foreignId('devanning_task_id')->nullable()->constrained('warehouse_tasks')->nullOnDelete();
            $table->unsignedInteger('allocation_version')->default(0); // 0 = nothing emitted yet; every emission carries the current version, 重算分摊 bumps it
            $table->boolean('allocation_stale')->default(false);       // members changed after an emission → 重算分摊 needed
            $table->uuid('arrived_event_id')->nullable();               // physical_container.arrived was published (cartage / sideloader)
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['container_no', 'eta_date']); // a box number is reused across voyages
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_containers');
    }
};
