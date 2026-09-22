<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-09-22 FIN-13 (CHANGE_REQUESTS #140): a recorded payment can be voided. Nothing is ever deleted — 作废收款 writes an
 * offsetting negative row that points at the payment it voids (`void_of_payment_id`) and carries the reason (`note`); the invoice's
 * paid amount and status are recomputed from the sum of all rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('note')->nullable()->after('reference');
            $table->foreignId('void_of_payment_id')->nullable()->after('recorded_by')->constrained('payments');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('void_of_payment_id');
            $table->dropColumn('note');
        });
    }
};
