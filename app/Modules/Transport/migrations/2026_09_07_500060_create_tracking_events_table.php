<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->string('status', 100);
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->string('source', 20);
            $table->timestamp('occurred_at')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shipment_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
    }
};
