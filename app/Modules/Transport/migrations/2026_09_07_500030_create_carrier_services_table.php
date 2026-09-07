<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->string('source', 20);
            $table->string('service_level', 20);
            $table->unsignedInteger('default_eta_days')->nullable();
            $table->boolean('active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['carrier_id', 'source', 'service_level']);
            $table->index(['source', 'service_level', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_services');
    }
};
