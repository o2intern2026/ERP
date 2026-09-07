<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->string('source', 20);
            $table->string('service_level', 20);
            $table->unsignedBigInteger('cost_cents');
            $table->unsignedBigInteger('customer_price_cents');
            $table->decimal('markup_percent', 5, 2)->nullable();
            $table->unsignedInteger('eta_days');
            $table->boolean('is_recommended')->default(false);
            $table->boolean('is_cheapest')->default(false);
            $table->boolean('is_fastest')->default(false);
            $table->string('quote_stage', 20);
            $table->string('status', 30)->default('quoted');
            $table->string('selected_by', 20)->nullable();
            $table->foreignId('selected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at');
            $table->timestamp('expires_at');
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'quote_stage', 'status']);
            $table->index(['source', 'service_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_quotes');
    }
};
