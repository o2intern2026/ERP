<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('recipient_name', 150)->nullable();
            $table->foreignId('signature_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->json('photo_document_ids')->nullable();
            $table->foreignId('pod_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('failure_reason', 100)->nullable();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shipment_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pods');
    }
};
