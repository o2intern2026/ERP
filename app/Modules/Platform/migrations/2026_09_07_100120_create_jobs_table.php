<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business Job (ERP_PLAN §1.6) — the backbone every business record hangs off. Written only through JobService.
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_no', 20)->unique();
            $table->foreignId('client_id')->constrained();
            $table->string('job_type', 20);
            $table->string('operational_status', 20)->default('open')->index();
            $table->string('revenue_status', 30)->default('unbilled');
            $table->string('cost_status', 30)->default('estimated');
            $table->bigInteger('estimated_revenue_cents')->default(0);
            $table->bigInteger('actual_revenue_cents')->default(0);
            $table->bigInteger('estimated_cost_cents')->default(0);
            $table->bigInteger('actual_cost_cents')->default(0);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
