<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained('carriers')->restrictOnDelete();
            $table->string('invoice_no');
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedBigInteger('total_cents');
            $table->string('status', 20)->default('received');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['carrier_id', 'invoice_no']);
            $table->index(['status', 'period_to']);
        });

        Schema::create('carrier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_invoice_id')->constrained('carrier_invoices')->cascadeOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('tracking_number')->index();
            $table->unsignedBigInteger('billed_cents');
            $table->unsignedBigInteger('expected_cents');
            $table->bigInteger('variance_cents');
            $table->boolean('matched')->default(false);
            $table->text('note')->nullable();

            $table->index(['carrier_invoice_id', 'matched']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_invoice_lines');
        Schema::dropIfExists('carrier_invoices');
    }
};
