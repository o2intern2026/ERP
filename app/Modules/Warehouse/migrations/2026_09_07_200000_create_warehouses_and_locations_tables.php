<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('state', 3)->nullable();
            $table->boolean('active')->default(true);
            // Per weekday open/close, e.g. {"mon": ["07:00", "17:00"], ...}: hours_business vs hours_after_hours are entered by the supervisor, this is the reference.
            $table->json('business_hours')->nullable();
            $table->timestamps();
        });

        // Four-level location code warehouse / zone / aisle / bin → full_code (ERP_PLAN §4.2).
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('zone', 10);
            $table->string('aisle', 10);
            $table->string('bin', 10);
            $table->string('full_code', 40);
            $table->string('type', 20)->default('storage');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['warehouse_id', 'full_code']);
            $table->index(['warehouse_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('warehouses');
    }
};
