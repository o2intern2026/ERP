<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('shipment_no', 30)->unique();
            $table->foreignId('job_id')->constrained('jobs')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // Orders and Warehouse land at M3/M4. X2 adds their FKs after rebasing those checkpoints.
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('fulfilment_id')->nullable()->index();
            $table->string('shipment_type', 20);
            $table->string('status', 30)->index();
            $table->unsignedBigInteger('selected_quote_id')->nullable()->index();
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->restrictOnDelete();
            $table->string('service_level', 20)->nullable();
            $table->string('booking_ref')->nullable();
            $table->string('tracking_number')->nullable()->index();
            $table->foreignId('waybill_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('consignment_note_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->boolean('tailgate_required')->default(false);
            $table->unsignedBigInteger('delivery_run_id')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
