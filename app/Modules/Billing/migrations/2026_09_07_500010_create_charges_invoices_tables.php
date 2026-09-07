<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Charge lines produced by the engine (A6a) — with rate + calculation snapshots and a business idempotency key.
        Schema::create('charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('jobs');
            $table->foreignId('client_id')->constrained();
            $table->date('charge_date')->index();
            $table->foreignId('charge_code_id')->constrained();
            $table->foreignId('rate_card_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('rate_card_version')->nullable();
            $table->foreignId('rate_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('uom', 20);
            $table->decimal('qty', 12, 3);
            $table->bigInteger('rate_snapshot_cents')->nullable();
            $table->bigInteger('amount_cents')->default(0);
            $table->json('calculation_snapshot_json')->nullable();
            $table->string('tax_treatment', 20)->default('gst_10');
            $table->string('status', 20)->default('pending')->index();
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_activity_id', 80)->nullable();
            $table->unsignedInteger('activity_version')->default(1);
            $table->foreignId('reversal_of_charge_id')->nullable()->constrained('charges')->nullOnDelete();
            $table->boolean('is_manual')->default(false);
            $table->string('manual_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('invoice_line_id')->nullable()->index(); // invoice_lines is created below
            $table->timestamps();
            $table->unique(['source_activity_id', 'charge_code_id', 'activity_version'], 'charges_activity_unique');
            $table->index(['client_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 20)->unique();
            $table->foreignId('client_id')->constrained();
            $table->string('invoice_type', 20);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('bill_to_name'); // client snapshot at issue time
            $table->string('bill_to_address')->nullable();
            $table->string('bill_to_abn', 20)->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->boolean('is_overdue')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->bigInteger('paid_amount_cents')->default(0);
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('gst_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('jobs');
            $table->unique(['invoice_id', 'job_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->string('charge_code', 40);
            $table->string('description');
            $table->decimal('qty', 12, 3);
            $table->string('uom', 20);
            $table->bigInteger('amount_cents');
            $table->string('tax_treatment', 20);
            $table->bigInteger('gst_cents')->default(0);
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_no', 20)->unique();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('client_id')->constrained();
            $table->string('reason');
            $table->bigInteger('amount_cents');
            $table->bigInteger('gst_cents')->default(0);
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charge_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->bigInteger('amount_cents');
            $table->bigInteger('gst_cents')->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->bigInteger('amount_cents');
            $table->date('paid_at');
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('customer_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_no', 20)->unique();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->foreignId('client_id')->constrained();
            $table->unsignedBigInteger('order_id')->nullable()->index(); // orders is an X1 table (no FK)
            $table->string('stage', 20)->default('preliminary');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('gst_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('customer_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_quote_id')->constrained()->cascadeOnDelete();
            $table->string('charge_code', 40);
            $table->string('description');
            $table->decimal('qty', 12, 3);
            $table->string('uom', 20);
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('transport_quote_id')->nullable(); // X2 table (no FK)
            $table->json('assumptions')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['customer_quote_lines', 'customer_quotes', 'payments', 'credit_note_lines', 'credit_notes', 'invoice_lines', 'invoice_jobs', 'invoices', 'charges'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
