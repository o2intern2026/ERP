<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('job_id')->constrained('jobs')->restrictOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->unsignedBigInteger('expected_cost_cents');
            $table->unsignedBigInteger('actual_cost_cents')->nullable();
            $table->bigInteger('variance_cents')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'confirmed_at']);
            $table->index(['carrier_id', 'confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_costs');
    }
};
