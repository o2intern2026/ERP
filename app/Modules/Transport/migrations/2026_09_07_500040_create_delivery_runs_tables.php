<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_no', 30)->unique();
            $table->date('run_date')->index();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->string('vehicle', 100);
            $table->string('status', 20)->default('planned')->index();
            $table->timestamps();
        });

        Schema::create('run_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_run_id')->constrained('delivery_runs')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->unsignedInteger('seq');
            $table->timestamp('eta')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['delivery_run_id', 'shipment_id']);
            $table->unique(['delivery_run_id', 'seq']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->foreign('delivery_run_id')->references('id')->on('delivery_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['delivery_run_id']);
        });

        Schema::dropIfExists('run_stops');
        Schema::dropIfExists('delivery_runs');
    }
};
