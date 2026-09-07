<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work tasks = execution record and billing trigger (task.completed) for receiving, putaway, devanning, VAS … (ERP_PLAN §4.2).
        Schema::create('warehouse_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_no', 20)->unique();
            $table->string('task_type', 20)->index();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('client_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('source_type', 20)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('fulfilment_id')->nullable();
            $table->foreignId('asn_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('container_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('priority')->default(5);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('exception_reason')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->decimal('billable_qty', 10, 3)->nullable();
            $table->string('billable_uom', 20)->nullable();
            $table->decimal('hours_business', 6, 2)->nullable();
            $table->decimal('hours_after_hours', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('billable_event_id')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('warehouse_task_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('warehouse_tasks')->cascadeOnDelete();
            $table->foreignId('stock_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asn_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('required_qty')->default(0);
            $table->integer('completed_qty')->default(0);
            $table->timestamp('confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_task_lines');
        Schema::dropIfExists('warehouse_tasks');
    }
};
