<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 入库单 (goods receipt): one receiving batch of an ASN (预报单). receipt_no = {asn_no}-R{batch_no}; every batch rolls up to the ASN.
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 40)->unique();
            $table->foreignId('asn_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->foreignId('job_id')->nullable()->constrained('jobs')->nullOnDelete();
            $table->unsignedSmallInteger('batch_no');
            $table->string('status', 20)->default('open'); // open | completed
            $table->boolean('unplanned')->default(false); // copied from the ASN
            $table->string('delivery_reference', 100)->nullable(); // 送货单号 / 车牌 / 司机
            $table->timestamp('opened_at');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            // Snapshot totals written at completion.
            $table->integer('expected_cartons')->default(0);
            $table->integer('received_cartons')->default(0);
            $table->integer('damaged_cartons')->default(0);
            $table->integer('variance_cartons')->default(0); // signed: received + damaged − expected
            $table->integer('line_count')->default(0);
            $table->integer('unit_count')->default(0);
            $table->integer('pallet_count')->default(0);
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['asn_id', 'batch_no']);
            $table->index(['client_id', 'status']);
        });

        // Per-line snapshot of what this batch received (the ASN line keeps the live figures).
        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asn_line_id')->constrained();
            $table->integer('expected_cartons')->default(0);
            $table->integer('received_cartons')->default(0);
            $table->integer('damaged_cartons')->default(0);
            $table->string('variance_reason')->nullable();
            $table->integer('unit_count')->default(0);
            $table->integer('pallet_count')->default(0);
            $table->timestamp('received_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['goods_receipt_id', 'asn_line_id']);
        });

        Schema::table('stock_units', function (Blueprint $table) {
            $table->foreignId('goods_receipt_id')->nullable()->after('asn_line_id')->constrained()->nullOnDelete(); // the batch that created the unit (PDF lists its labels)
        });

        Schema::table('asns', function (Blueprint $table) {
            $table->timestamp('receiving_completed_at')->nullable()->after('arrived_at'); // set when a batch completes and every line has been received
        });
    }

    public function down(): void
    {
        Schema::table('asns', fn (Blueprint $table) => $table->dropColumn('receiving_completed_at'));
        Schema::table('stock_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_id');
        });
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
